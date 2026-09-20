<?php

namespace App\Actions\Documents;

use App\Enums\SecurityEventType;
use App\Models\DocumentFile;
use App\Models\DocumentSignature;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * §15 undo: an office takes its own mark back off a document.
 *
 * The client asked for this on 2026-09-20 alongside one-signature-per-office,
 * and the two are the same idea from both ends: an office gets exactly one
 * signature, so it has to be able to correct a wrong one. Their scope was
 * "kapag in-undo habang nasa office pa" -- while the folder is still on your
 * desk. DocumentSignaturePolicy::undo holds every condition; this action does
 * the work once the answer is yes.
 *
 * WHY THE ROW IS DELETED RATHER THAN MARKED WITHDRAWN. The whole feature is
 * that the signature was never meant to be there. A soft-deleted row would
 * keep appearing in the integrity sweep, would still hold its (file, user,
 * purpose) unique slot -- blocking the corrected signature it exists to make
 * room for -- and would print on a certificate. What survives instead is the
 * §21 audit line, which names the office, the serial and the document, and
 * outlives the row it describes. That is the record a dispute needs; the row
 * was the claim, and the claim is being withdrawn.
 *
 * THE STAMPED VERSION GOES WITH IT, and that is the one place this action
 * deletes bytes. A version whose only reason to exist was to carry a mark
 * that has been withdrawn is not history, it is litter -- and leaving it as
 * the CURRENT version would mean the next office reads a document showing a
 * signature the register says was never made. The version that was signed is
 * never touched.
 */
final class UndoSignature
{
    /**
     * @param  string|null  $reason
     *                               Why it was withdrawn, appended to the audit line. Null for the
     *                               signer pressing Undo, where the act speaks for itself;
     *                               WithdrawSignaturesOnReturn passes one, because a mark that
     *                               vanished without anybody touching it needs the log to say so.
     */
    public function handle(DocumentSignature $signature, User $actor, ?string $reason = null): void
    {
        /*
         * Everything the audit line needs, read BEFORE the row is gone.
         * Afterwards there is nothing left to ask.
         */
        $document = $signature->document;
        $serial = $signature->serial;
        $office = $signature->signer_office ?? $signature->signer_name;
        $purpose = $signature->purpose;
        $control = $document === null ? '' : $document->control_number;

        DB::transaction(function () use ($signature): void {
            $this->deleteStampedVersion($signature);

            // The mark's own PNG. Deleted after the version that embedded it,
            // so a failure part-way never leaves a version pointing at bytes
            // that are gone.
            if ($signature->image_path !== null) {
                Storage::disk($signature->image_disk ?? 'documents')
                    ->delete($signature->image_path);
            }

            $signature->delete();
        });

        /*
         * Logged AFTER the commit, the same rule SignDocument follows: on
         * PostgreSQL a failed statement poisons the surrounding transaction,
         * and SecurityEvent::log swallows its own failures -- so a log write
         * that failed inside would silently roll the undo back.
         */
        SecurityEvent::log(
            SecurityEventType::SignatureUndone,
            sprintf(
                '%s withdrew the %s signature of %s on %s (serial %s)%s.',
                $actor->name,
                $purpose,
                $office,
                $control,
                $serial,
                $reason === null ? '' : ' because '.$reason,
            ),
            $actor,
            $control,
        );
    }

    /**
     * Remove the version this signature produced, if it produced one.
     *
     * Only ever the signature's OWN output, and only while it is still the
     * newest version -- the policy has already refused an undo with anything
     * uploaded after it, so in practice this is always the current file. The
     * check is repeated here anyway because this method deletes bytes, and a
     * guard that lives only in the caller is a guard that stops being true.
     */
    private function deleteStampedVersion(DocumentSignature $signature): void
    {
        if ($signature->stamped_file_id === null) {
            return;
        }

        /*
         * NEVER THE VERSION THAT WAS SIGNED.
         *
         * SignDocument stopped recording these two as the same row in QA on
         * 2026-09-20, but the guard belongs here as well: this method deletes
         * bytes, and the row it reads is data that a migration, a fixture or a
         * future caller could put in the broken shape. When they match there
         * is nothing this signature produced, so there is nothing to remove.
         */
        if ($signature->stamped_file_id === $signature->document_file_id) {
            return;
        }

        $stamped = DocumentFile::query()->find($signature->stamped_file_id);

        if ($stamped === null) {
            return;
        }

        $newer = DocumentFile::query()
            ->where('document_id', $stamped->document_id)
            ->where('version', '>', $stamped->version)
            ->exists();

        if ($newer) {
            return;
        }

        /*
         * NOR ONE ANOTHER SIGNATURE STILL CLAIMS. Two marks can end up
         * pointing at one produced version -- the dedupe path above is one way
         * -- and deleting it while the second still names it would leave that
         * signature describing a file nobody can open.
         */
        $claimedByAnother = DocumentSignature::query()
            ->where('stamped_file_id', $stamped->id)
            ->whereKeyNot($signature->getKey())
            ->exists();

        if ($claimedByAnother) {
            return;
        }

        // The row first, then the bytes. A row pointing at a missing file
        // renders as a broken download; bytes with no row are invisible and
        // get swept up by the retention job.
        $disk = $stamped->disk;
        $path = $stamped->path;

        $stamped->delete();

        Storage::disk($disk)->delete($path);
    }
}
