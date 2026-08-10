<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Enums\Role;
use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * §12. Writes in-app notifications.
 *
 * One row per USER, not per office. Read state is personal: an office-level row
 * would let one clerk clear the bell for thirty colleagues, and fixing that
 * needs a notification_reads join table which writes the same rows anyway and
 * turns the header count into a NOT EXISTS on every page render.
 */
class NotificationWriter
{
    /**
     * Fan out to the active members of an office.
     *
     * One indexed SELECT plus one bulk insert -- never a foreach of create()
     * calls, which turns a 10ms transition into 300ms and makes the forward
     * button look broken.
     *
     * @return int rows written
     */
    public function fanOutToOffice(
        NotificationType $type,
        Document $document,
        ?int $officeId,
        ?DocumentMovement $movement = null,
        ?User $except = null,
        ?string $dedupeSuffix = null,
    ): int {
        if ($officeId === null) {
            return 0;
        }

        // Only people who can actually OPEN the document get told about it.
        //
        // §2 limits a plain User to "their own documents", so notifying every
        // clerk in the receiving office would hand them a bell that 403s when
        // clicked -- worse than no notification. Recipients are therefore the
        // office's Admins, who are the ones §2 puts in charge of reviewing and
        // routing anyway.
        //
        // NotificationRecipientsTest asserts this stays true by checking every
        // recipient against DocumentPolicy::view.
        $recipients = User::query()
            ->active()
            ->inOffice($officeId)
            ->whereIn('role', [Role::Admin->value, Role::SuperAdmin->value])
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->id))
            ->pluck('id');

        if ($recipients->isEmpty()) {
            return 0;
        }

        $now = now();
        $dedupeKey = $this->dedupeKey($type, $document, $movement, $dedupeSuffix);

        $rows = $recipients->map(fn (int $userId) => [
            'user_id' => $userId,
            'document_id' => $document->id,
            'document_movement_id' => $movement?->id,
            'type' => $type->value,
            'dedupe_key' => $dedupeKey,
            'title' => $type->label(),
            'body' => mb_substr($this->body($type, $document), 0, 255),
            'control_number' => $document->control_number,
            'read_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        // insertOrIgnore, so re-running a sweep is a no-op rather than a
        // constraint violation. The unique(user_id, dedupe_key) index is what
        // makes idempotency real; this just keeps it quiet.
        return DB::table('notifications')->insertOrIgnore($rows);
    }

    /**
     * @return int rows written
     */
    public function toUser(
        NotificationType $type,
        Document $document,
        User $user,
        ?DocumentMovement $movement = null,
        ?string $dedupeSuffix = null,
    ): int {
        $now = now();

        return DB::table('notifications')->insertOrIgnore([[
            'user_id' => $user->id,
            'document_id' => $document->id,
            'document_movement_id' => $movement?->id,
            'type' => $type->value,
            'dedupe_key' => $this->dedupeKey($type, $document, $movement, $dedupeSuffix),
            'title' => $type->label(),
            'body' => mb_substr($this->body($type, $document), 0, 255),
            'control_number' => $document->control_number,
            'read_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]]);
    }

    /**
     * Idempotency lives on this string rather than on the movement FK, so
     * changing "overdue once per leg" to "once per day" is a suffix change
     * instead of a migration.
     */
    private function dedupeKey(
        NotificationType $type,
        Document $document,
        ?DocumentMovement $movement,
        ?string $suffix,
    ): string {
        $parts = [$type->value, (string) $document->id];

        if ($movement !== null) {
            $parts[] = (string) $movement->id;
        }

        if ($suffix !== null) {
            $parts[] = $suffix;
        }

        return mb_substr(implode(':', $parts), 0, 64);
    }

    private function body(NotificationType $type, Document $document): string
    {
        return match ($type) {
            NotificationType::Assigned => "{$document->control_number} — {$document->title}",
            NotificationType::Forwarded => "{$document->control_number} has arrived at your office.",
            NotificationType::Returned => "{$document->control_number} was returned for correction.",
            NotificationType::Pending => "{$document->control_number} is due soon.",
            NotificationType::Overdue => "{$document->control_number} has passed its expected completion date.",
        };
    }
}
