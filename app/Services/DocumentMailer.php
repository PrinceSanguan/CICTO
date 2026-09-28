<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Mail\DocumentNotificationMail;
use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\Office;
use App\Models\User;
use App\Support\OutgoingMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

use function Illuminate\Support\defer;

/**
 * §12 by email: tells an office that a document has been sent to it.
 *
 * Asked for on 2026-09-24. It rides on the same trigger as the bell --
 * DispatchDocumentNotifications calls this right beside NotificationWriter --
 * so an office is emailed exactly when it is belled: a document filed with it,
 * forwarded to it, returned to it, or resubmitted to it. The deadline sweep's
 * Pending and Overdue bells are NOT emailed; nobody asked, and they would
 * double the traffic through a quota the password resets depend on.
 *
 * SENT AFTER THE RESPONSE, NOT QUEUED. Nothing in this deployment runs a queue
 * worker (DEPLOYMENT.md, "Mail is sent inline"), so a queued message would sit
 * in the jobs table forever while the screen said it was sent. But sending
 * inline in the request, as the auth flows do, would hold every Forward and
 * Received click for one to three seconds per recipient -- and a submit to ten
 * departments for half a minute. `defer()` is the middle road: the same PHP
 * process sends the mail, with no worker, but only after the browser already
 * has its response. The cost is that a failure cannot be shown to anybody, so
 * each one is logged instead -- the bell has already been written either way.
 */
class DocumentMailer
{
    /** Whether a notification email can be sent at all on this host. */
    public static function enabled(): bool
    {
        return (bool) config('cicto.notifications.email') && OutgoingMail::isConfigured();
    }

    /**
     * Email the active members of an office -- and, for a return, the person
     * who filed the document -- about one movement.
     *
     * The recipients are the bell's recipients (NotificationWriter::
     * fanOutToOffice plus toUser), narrowed to accounts with a verified address:
     * an unverified one was never proven to belong to the person, and a typo in
     * it would send the document's details to a stranger.
     *
     * @return int how many emails were scheduled
     */
    public function send(
        NotificationType $type,
        Document $document,
        DocumentMovement $movement,
        User $actor,
        ?int $officeId,
        ?User $submitter = null,
    ): int {
        if (! self::enabled()) {
            return 0;
        }

        /** @var array<int, array{user: User, reason: string}> $recipients keyed by user id, so nobody is emailed twice */
        $recipients = [];

        if ($officeId !== null) {
            $office = Office::query()->find($officeId);

            $members = User::query()
                ->active()
                ->inOffice($officeId)
                ->whereNotNull('email_verified_at')
                ->whereKeyNot($actor->id)
                ->get();

            foreach ($members as $member) {
                $recipients[$member->id] = [
                    'user' => $member,
                    'reason' => 'you belong to '.($office->name ?? 'this office'),
                ];
            }
        }

        if (
            $submitter !== null
            && $submitter->id !== $actor->id
            && $submitter->is_active
            && $submitter->email_verified_at !== null
            && ! isset($recipients[$submitter->id])
        ) {
            $recipients[$submitter->id] = [
                'user' => $submitter,
                'reason' => 'you filed this document',
            ];
        }

        // A Confidential document is emailed only to people who may open it:
        // the rest of the office that filed it is exactly who it is kept from.
        if ($document->is_confidential) {
            $recipients = array_filter(
                $recipients,
                static fn (array $recipient): bool => $recipient['user']->can('view', $document),
            );
        }

        if ($recipients === []) {
            return 0;
        }

        // Loaded now, while the request's models are warm, rather than one
        // lazy query per recipient inside the deferred send.
        $document->loadMissing(['documentType', 'originatingOffice']);
        $movement->loadMissing(['fromOffice', 'toOffice']);

        defer(function () use ($type, $document, $movement, $actor, $recipients): void {
            foreach ($recipients as $recipient) {
                $this->deliver($type, $document, $movement, $actor, $recipient['user'], $recipient['reason']);
            }
        });

        return count($recipients);
    }

    /**
     * One message per person, so one bad address cannot sink the rest.
     *
     * A single message to the whole office would be one SMTP transaction, but
     * the server refuses the WHOLE message if it refuses any one recipient --
     * a colleague's mistyped address would silence everybody else's copy.
     */
    private function deliver(
        NotificationType $type,
        Document $document,
        DocumentMovement $movement,
        User $actor,
        User $user,
        string $reason,
    ): void {
        try {
            Mail::to($user)->send(new DocumentNotificationMail($type, $document, $movement, $actor, $user, $reason));
        } catch (\Throwable $e) {
            Log::error('Document notification email failed', [
                'document_id' => $document->id,
                'movement_id' => $movement->id,
                'user_id' => $user->id,
                'type' => $type->value,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
