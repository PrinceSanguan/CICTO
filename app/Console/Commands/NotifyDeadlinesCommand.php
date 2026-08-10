<?php

namespace App\Console\Commands;

use App\Enums\NotificationType;
use App\Models\Builders\DocumentBuilder;
use App\Models\Document;
use App\Services\NotificationWriter;
use App\Support\Deadlines;
use Illuminate\Console\Command;

/**
 * §12's two swept triggers: "pending" (due soon) and "overdue".
 *
 * Those are STATES, not events, so nothing in the request cycle can emit them.
 *
 * If the host has no cron this command never runs -- and the only thing lost is
 * the push. The Overdue filter, the row badges and the dashboard counts all
 * stay correct, because overdue is a live query predicate rather than a stored
 * flag. Say that plainly to the client instead of discovering it at handover.
 */
class NotifyDeadlinesCommand extends Command
{
    protected $signature = 'cicto:notify-deadlines {--dry-run : Report what would be sent without writing}';

    protected $description = 'Notify offices about documents that are due soon or overdue';

    public function handle(NotificationWriter $writer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $today = Deadlines::now()->toDateString();

        $warned = $this->sweep(
            NotificationType::Pending,
            Document::query()->active()->approachingDeadline(),
            'deadline_warned_at',
            $writer,
            $dryRun,
            $today,
        );

        $overdue = $this->sweep(
            NotificationType::Overdue,
            Document::query()->active()->overdue(),
            'overdue_notified_at',
            $writer,
            $dryRun,
            $today,
        );

        $this->info(sprintf(
            '%s%d approaching, %d overdue.',
            $dryRun ? '[dry run] ' : '',
            $warned,
            $overdue,
        ));

        return self::SUCCESS;
    }

    /**
     * @param  DocumentBuilder  $query
     */
    private function sweep(
        NotificationType $type,
        $query,
        string $stampColumn,
        NotificationWriter $writer,
        bool $dryRun,
        string $today,
    ): int {
        $count = 0;

        // chunkById, not chunk: the sweep mutates the rows it is paging over,
        // and offset paging would skip records as the result set shifts.
        $query->with('openMovement')->chunkById(200, function ($documents) use (
            $type, $stampColumn, $writer, $dryRun, $today, &$count
        ): void {
            foreach ($documents as $document) {
                $count++;

                if ($dryRun) {
                    continue;
                }

                $writer->fanOutToOffice(
                    type: $type,
                    document: $document,
                    officeId: $document->openMovement?->to_office_id,
                    movement: $document->openMovement,
                    // One reminder per document per day. Changing the cadence
                    // is a change to this suffix, not a migration.
                    dedupeSuffix: $today,
                );

                // withoutTimestamps: saveQuietly() suppresses events but still
                // bumps updated_at, and the public scan page shows updated_at
                // as "Last updated". A nightly sweep must not make every
                // overdue document look like somebody just worked on it.
                Document::withoutTimestamps(
                    fn () => $document->forceFill([$stampColumn => Deadlines::now()])->saveQuietly(),
                );
            }
        });

        return $count;
    }
}
