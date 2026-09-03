<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\TransitionDocument;
use App\Enums\DocumentStatus;
use App\Enums\MovementAction;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * §4 Admin Panel: the four status tiles, the searchable register and the
 * twelve-month trend.
 *
 * The tile arithmetic is the part worth pinning. The client names four buckets
 * over a six-state workflow, so "Pending" and "Approved" are interpretations,
 * and an interpretation that drifts between the tile and the table filter is
 * how a records officer ends up quoting two different numbers for the same
 * question.
 */
class AdminPanelTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    /**
     * Park a document in a stage the workflow can no longer reach.
     *
     * `approved` and `rejected` were removed as ACTIONS on 2026-09-03, but they
     * are still STATUSES: every document the client processed before that date
     * is stored in one of them, and the four dashboard tiles exist to bucket
     * exactly those six stored values. Writing the column directly is the only
     * way left to build that fixture, and it is honest -- the tiles read
     * documents.status and have never cared how a row got there.
     */
    private function park(Document $document, DocumentStatus $status): Document
    {
        $document->forceFill(['status' => $status->value])->save();

        return $document;
    }

    /** Walk a document to a terminal state. */
    private function advance(Document $document, User $admin, MovementAction ...$actions): void
    {
        foreach ($actions as $action) {
            $document->refresh();
            app(TransitionDocument::class)->handle(
                document: $document,
                action: $action,
                actor: $admin,
                expectedMovementId: $document->openMovement?->id,
            );
        }
    }

    public function test_the_tiles_bucket_six_workflow_states_into_the_four_the_client_asked_for(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $clerk = $this->staff($office);

        // One left pending, one approved, one completed, one rejected. Two of
        // those four stages are unreachable now, so they are written directly
        // -- see park(): the tiles bucket stored statuses, and the client's
        // database is full of rows in both.
        $this->registerDocument($office, $clerk);

        $this->park($this->registerDocument($office, $clerk), DocumentStatus::Approved);

        $this->advance(
            $this->registerDocument($office, $clerk),
            $admin,
            MovementAction::Received,
            MovementAction::Completed,
        );

        $this->park($this->registerDocument($office, $clerk), DocumentStatus::Rejected);

        $stats = $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->viewData('page')['props']['stats'];

        $this->assertSame(4, $stats['total']);
        $this->assertSame(1, $stats['pending']);

        // approved AND completed. Splitting them would report fewer approvals
        // than the office actually made.
        $this->assertSame(2, $stats['approved']);
        $this->assertSame(1, $stats['rejected']);

        // The four buckets must account for every document exactly once.
        $this->assertSame(
            $stats['total'],
            $stats['pending'] + $stats['approved'] + $stats['rejected'],
        );
    }

    public function test_a_tile_filter_returns_only_that_bucket(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $clerk = $this->staff($office);

        $this->registerDocument($office, $clerk);

        $rejected = $this->park($this->registerDocument($office, $clerk), DocumentStatus::Rejected);

        $props = $this->actingAs($admin)
            ->get(route('admin.dashboard', ['status' => 'rejected']))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertCount(1, $props['documents']['data']);
        $this->assertSame(
            $rejected->control_number,
            $props['documents']['data'][0]['control_number'],
        );

        // An unknown bucket must not silently filter to nothing.
        $props = $this->actingAs($admin)
            ->get(route('admin.dashboard', ['status' => 'nonsense']))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertNull($props['filters']['status']);
        $this->assertCount(2, $props['documents']['data']);
    }

    public function test_the_register_is_searchable_and_scoped_to_the_office(): void
    {
        $office = $this->office('MPDO');
        $admin = $this->admin($office);
        $clerk = $this->staff($office);

        $wanted = $this->registerDocument($office, $clerk);
        $wanted->forceFill(['title' => 'Barangay drainage clearance'])->save();

        $other = $this->registerDocument($office, $clerk);
        $other->forceFill(['title' => 'Payroll adjustment'])->save();

        // Another office's document must never appear, searched for or not.
        $elsewhere = $this->office('HRMO');
        $hidden = $this->registerDocument($elsewhere, $this->staff($elsewhere));

        $rows = $this->actingAs($admin)
            ->get(route('admin.dashboard', ['q' => 'DRAINAGE']))
            ->assertOk()
            ->viewData('page')['props']['documents']['data'];

        // Case-insensitive on every driver: the collation is not guaranteed.
        $this->assertCount(1, $rows);
        $this->assertSame($wanted->control_number, $rows[0]['control_number']);

        $all = $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->viewData('page')['props']['documents']['data'];

        $this->assertNotContains(
            $hidden->control_number,
            collect($all)->pluck('control_number')->all(),
        );
    }

    public function test_the_register_paginates_rather_than_rendering_the_whole_office(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $clerk = $this->staff($office);

        for ($i = 0; $i < 12; $i++) {
            $this->registerDocument($office, $clerk);
        }

        $page = $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->viewData('page')['props']['documents'];

        $this->assertCount(10, $page['data']);
        $this->assertSame(12, $page['total']);
        $this->assertSame(2, $page['last_page']);

        $second = $this->actingAs($admin)
            ->get(route('admin.dashboard', ['page' => 2]))
            ->assertOk()
            ->viewData('page')['props']['documents'];

        $this->assertCount(2, $second['data']);
        $this->assertSame(11, $second['from']);
    }

    public function test_the_trend_reports_every_month_including_empty_ones(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);

        $trend = $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->viewData('page')['props']['trend'];

        // A line chart that skips months with no documents misreports the shape
        // of the year, so the grid is built in PHP rather than taken from the
        // query result.
        $this->assertCount(12, $trend);

        foreach ($trend as $point) {
            $this->assertSame(
                ['month', 'label', 'approved', 'pending', 'rejected'],
                array_keys($point),
            );
        }

        $this->assertSame(
            now()->startOfMonth()->format('Y-m'),
            $trend[11]['month'],
            'The last bucket should be the current month.',
        );
    }

    public function test_a_row_offers_no_download_when_there_is_no_file(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $clerk = $this->staff($office);

        $document = $this->registerDocument($office, $clerk);
        $document->files()->delete();

        $rows = $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->viewData('page')['props']['documents']['data'];

        // Offering the button would link to a route that can only answer 404.
        $this->assertNull($rows[0]['current_file_id']);
    }

    public function test_a_plain_user_cannot_reach_the_admin_panel(): void
    {
        $office = $this->office();

        $this->actingAs($this->staff($office))
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }
}
