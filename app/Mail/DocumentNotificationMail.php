<?php

namespace App\Mail;

use App\Enums\MovementAction;
use App\Enums\NotificationType;
use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The email twin of a §12 bell: a document has been sent to your office.
 *
 * Asked for on 2026-09-24 -- "dapat po may notification na lalabas pag may nag
 * sesend ng document na papunta or nag punta sa office nyo through email" -- so
 * the office hears about a folder without having CICTO open. It carries the
 * details a reader needs to know what arrived (control number, title, who sent
 * it, the remarks) and one link, for when they want to act on it.
 *
 * Sent by App\Services\DocumentMailer, which decides who gets one.
 */
class DocumentNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly NotificationType $type,
        public readonly Document $document,
        public readonly DocumentMovement $movement,
        public readonly User $actor,
        public readonly User $recipient,
        /** Why this person got it -- "you belong to X" or "you filed it". */
        public readonly string $reason,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[CICTO] {$this->type->label()}: {$this->document->control_number}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.document-notification',
            text: 'mail.document-notification-text',
            with: [
                'recipientName' => $this->recipient->name,
                'headline' => $this->type->label(),
                'intro' => $this->intro(),
                'details' => $this->details(),
                'remarksLabel' => $this->remarksLabel(),
                'remarks' => $this->remarks(),
                'url' => route('documents.show', $this->document),
                'reason' => $this->reason,
            ],
        );
    }

    /** One sentence saying what happened, naming who did it. */
    private function intro(): string
    {
        $actor = $this->actor->name;
        $from = $this->movement->from_office_id === null ? null : $this->movement->fromOffice->name;
        $to = $this->movement->to_office_id === null ? 'your office' : $this->movement->toOffice->name;

        // "Maria Cruz (Treasury)" when the sending office is known -- in
        // brackets, because the official names are long and "of Office of the
        // City Mayor - ..." does not read. The genesis leg has no sending
        // office, so it is just the name there. So is a Super Admin's: they
        // belong to no office, and bracketing the one the folder left would
        // say they work there.
        $who = $from === null || $this->actor->isSuperAdmin() ? $actor : "{$actor} ({$from})";

        return match ($this->type) {
            NotificationType::Assigned => "{$actor} filed a new document with {$to}.",
            NotificationType::Forwarded => "{$who} sent this document to {$to}.",
            NotificationType::Resubmitted => "{$who} corrected this document and sent it back to {$to}.",
            NotificationType::Returned => "{$who} returned this document to {$to} for correction.",
            NotificationType::Rejected => "{$who} rejected this document.",
            // Swept types never come through DocumentMailer, but the match has
            // to be total.
            NotificationType::Pending => 'This document is due soon.',
            NotificationType::Overdue => 'This document has passed its expected completion date.',
            // Bell only -- see BroadcastDocument -- but the match is total.
            NotificationType::Broadcast => "{$actor} sent this document to every office.",
        };
    }

    /**
     * The facts, in the order the View Documents sheet lists them.
     *
     * @return array<string, string>
     */
    private function details(): array
    {
        $document = $this->document;

        return array_filter([
            'Control number' => $document->control_number,
            'Title' => $document->title,
            'Document type' => $document->documentType?->name,
            'Priority' => $document->priority->label(),
            'Originating office' => $document->originatingOffice?->name,
            'Sent by' => $this->actor->name,
            'Sent on' => $this->movement->arrived_at?->format('F j, Y g:i A'),
            'Expected completion' => $document->due_at?->format('F j, Y'),
        ], fn (?string $value) => $value !== null && $value !== '');
    }

    /**
     * What the sender wrote. On every leg but the first that is the leg's own
     * remarks; the Remarks box on the filing form is saved on the DOCUMENT
     * instead (RegisterDocument leaves the genesis leg's blank), so a "new
     * document" email read from the leg alone would drop it.
     */
    private function remarks(): ?string
    {
        if ($this->movement->action === MovementAction::Registered) {
            return $this->movement->remarks ?? $this->document->remarks;
        }

        return $this->movement->remarks;
    }

    private function remarksLabel(): string
    {
        return in_array($this->type, [NotificationType::Returned, NotificationType::Rejected], true)
            ? 'Reason'
            : 'Remarks';
    }
}
