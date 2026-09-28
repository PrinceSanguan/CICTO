<?php

namespace App\Actions\Documents;

use App\Enums\NotificationType;
use App\Models\Document;
use App\Models\User;
use App\Services\NotificationWriter;
use App\Support\Deadlines;
use Illuminate\Support\Facades\DB;

/**
 * "Broadcast to ALL offices" -- the client's DTS_Office_Routing_Paths.pdf
 * (2026-09-25), step 4 of an Executive Order and step 2 of a Memorandum
 * Circular.
 *
 * WHAT IT IS NOT: "send to every department at the same time", which the
 * client removed on 2026-09-19. That made one COPY per department, each with
 * its own control number and its own trail. A broadcast makes nothing: there
 * is still one document and one folder, and the folder carries on along its
 * route (an Executive Order on to the Archive for filing) exactly as before.
 *
 * WHAT IT IS: every office is told, and every office may open it. Stamped on
 * the document -- when, and by whom -- and from then on DocumentPolicy::view
 * lets anybody who belongs to an office read it. Reading is all it grants: the
 * workflow, signing, comments and archiving stay with the offices it actually
 * passed through (DocumentPolicy::involved).
 *
 * THE BELL ONLY, NOT EMAIL. One broadcast is a notification for every account
 * in the city -- about 160 today. Emailing them all from the shared Gmail
 * account would spend a third of its daily allowance in one press, and a
 * second broadcast that day would start failing sign-in codes.
 */
final class BroadcastDocument
{
    public function __construct(private readonly NotificationWriter $writer) {}

    /**
     * @return int how many people were notified
     *
     * @throws \LogicException when it was broadcast in the meantime -- the
     *                         policy said no to the second tab, but only
     *                         before this row was locked
     */
    public function handle(Document $document, User $actor): int
    {
        DB::transaction(function () use ($document, $actor): void {
            $locked = Document::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();

            if ($locked->broadcast_at !== null) {
                throw new \LogicException('This document has already been sent to every office.');
            }

            $locked->forceFill([
                'broadcast_at' => Deadlines::now(),
                'broadcast_by_id' => $actor->id,
            ])->save();

            $document->setRawAttributes($locked->getAttributes(), true);
        });

        // After the commit: a notification failure must not undo the
        // broadcast, and the rows it writes must never outlive a rollback.
        return $this->writer->fanOutToEveryone(
            type: NotificationType::Broadcast,
            document: $document,
            except: $actor,
        );
    }
}
