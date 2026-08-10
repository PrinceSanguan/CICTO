<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentStatus;
use App\Enums\DueState;
use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

class DeadlineTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_document_becomes_overdue_by_the_clock_alone_with_no_data_change(): void
    {
        $office = $this->office();
        $type = $this->documentType(turnaroundDays: 5);

        Carbon::setTestNow('2026-08-01 08:00:00');
        $document = $this->registerDocument($office, $this->staff($office), $type);

        $this->assertSame(DueState::OnTrack, $document->dueState());

        // Overdue is a query predicate, not a stored flag -- nothing is written
        // when a deadline passes, which is why the feature works on a host with
        // no cron at all.
        Carbon::setTestNow('2026-08-20 08:00:00');

        $this->assertSame(DueState::Overdue, $document->fresh()->dueState());
        $this->assertTrue(
            Document::query()->overdue()->whereKey($document->id)->exists(),
        );
    }

    public function test_the_badge_and_the_query_never_disagree(): void
    {
        $office = $this->office();
        $staff = $this->staff($office);

        Carbon::setTestNow('2026-08-01 08:00:00');

        $onTrack = $this->registerDocument($office, $staff, $this->documentType(30));
        $soon = $this->registerDocument($office, $staff, $this->documentType(1));
        $late = $this->registerDocument($office, $staff, $this->documentType(1));

        // Push one past its deadline.
        Carbon::setTestNow('2026-08-05 08:00:00');

        $overdueIds = Document::query()->overdue()->pluck('id')->all();
        $approachingIds = Document::query()->approachingDeadline()->pluck('id')->all();

        foreach (Document::query()->get() as $document) {
            $state = $document->dueState();

            $this->assertSame(
                in_array($document->id, $overdueIds, true),
                $state === DueState::Overdue,
                "Overdue scope disagreed with the badge for {$document->control_number}.",
            );

            $this->assertSame(
                in_array($document->id, $approachingIds, true),
                $state === DueState::Approaching,
                "Approaching scope disagreed with the badge for {$document->control_number}.",
            );
        }

        $this->assertContains($late->id, $overdueIds);
        $this->assertContains($soon->id, $overdueIds);
        $this->assertSame(DueState::OnTrack, $onTrack->fresh()->dueState());
    }

    public function test_a_terminal_document_stops_the_clock(): void
    {
        $office = $this->office();

        Carbon::setTestNow('2026-08-01 08:00:00');
        $document = $this->registerDocument($office, $this->staff($office), $this->documentType(1));

        Carbon::setTestNow('2026-09-01 08:00:00');
        $document->forceFill([
            'status' => DocumentStatus::Completed,
            'completed_at' => now(),
        ])->save();

        $this->assertSame(DueState::Closed, $document->fresh()->dueState());
        $this->assertFalse(
            Document::query()->overdue()->whereKey($document->id)->exists(),
            'A finished document is not overdue -- the clock stopped when it completed.',
        );
    }

    public function test_time_at_the_current_office_is_measured_from_the_open_leg(): void
    {
        $office = $this->office();

        Carbon::setTestNow('2026-08-01 08:00:00');
        $document = $this->registerDocument($office, $this->staff($office));

        Carbon::setTestNow('2026-08-01 14:30:00');

        $this->assertSame(390, $document->fresh()->minutesAtCurrentOffice());
    }
}
