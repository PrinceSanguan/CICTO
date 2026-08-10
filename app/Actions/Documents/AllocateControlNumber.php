<?php

namespace App\Actions\Documents;

use App\Models\DocumentNumberSequence;
use App\Models\Office;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of document_number_sequences.
 *
 * Never Document::max('id') + 1 and never count() + 1: both read a value that
 * another request can change before the insert lands, and the failure is a
 * duplicate control number on a printed folder.
 */
final class AllocateControlNumber
{
    /**
     * Format: {OFFICE}-{YYYY}-{NNNNN}, e.g. MPDO-2026-00042.
     *
     * Must be called inside the caller's transaction -- RegisterDocument wraps
     * the sequence, the document, the genesis movement and the first file
     * together, so a failed upload never burns a number.
     */
    public function handle(Office $office, ?CarbonInterface $at = null): string
    {
        $year = ($at ?? now())->year;

        return DB::transaction(function () use ($office, $year): string {
            $row = DocumentNumberSequence::query()
                ->where('office_id', $office->id)
                ->where('period_year', $year)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                $row = DocumentNumberSequence::create([
                    'office_id' => $office->id,
                    'period_year' => $year,
                    'last_number' => 0,
                ]);

                // Re-select under the lock. Two concurrent registrations can
                // both miss above; the unique(office_id, period_year) index
                // makes one of them fail, and this reload picks up the winner.
                $row = DocumentNumberSequence::query()
                    ->where('office_id', $office->id)
                    ->where('period_year', $year)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $row->increment('last_number');

            return sprintf('%s-%d-%05d', $office->code, $year, $row->last_number);
        });
    }
}
