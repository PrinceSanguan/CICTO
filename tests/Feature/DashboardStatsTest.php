<?php

namespace Tests\Feature;

use App\Actions\Documents\ArchiveDocument;
use App\Actions\Documents\TransitionDocument;
use App\Enums\MovementAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * §18 dashboard figures.
 *
 * The property worth pinning is that ARCHIVED documents still count. Per
 * decision D16 archiving is filing, not deletion; excluding filed rows would
 * make an office's completed total fall every time somebody tidied up, which is
 * the opposite of what the archive is for.
 */
class DashboardStatsTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    public function test_archiving_a_document_does_not_reduce_the_headline_totals(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $clerk = $this->staff($office);

        $document = $this->registerDocument($office, $clerk);

        foreach ([
            MovementAction::Received,
            MovementAction::Approved,
            MovementAction::Completed,
        ] as $action) {
            $document->refresh();
            app(TransitionDocument::class)->handle(
                document: $document,
                action: $action,
                actor: $admin,
                expectedMovementId: $document->openMovement?->id,
            );
        }

        $before = $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->viewData('page')['props']['summary'];

        app(ArchiveDocument::class)->archive($document->fresh(), $admin);

        $after = $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->viewData('page')['props']['summary'];

        $this->assertSame($before['total'], $after['total']);
        $this->assertSame($before['processed'], $after['processed']);
        $this->assertSame($before['approval_rate'], $after['approval_rate']);
    }

    public function test_the_approval_rate_is_a_dash_before_anything_is_decided(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);

        $this->registerDocument($office, $this->staff($office));

        $summary = $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->viewData('page')['props']['summary'];

        // null renders as an em dash. "0%" would read as "everything was
        // rejected", which is a very different statement.
        $this->assertNull($summary['approval_rate']);
    }

    public function test_the_dashboard_and_the_report_quote_the_same_totals(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $clerk = $this->staff($office);

        foreach (range(1, 3) as $ignored) {
            $this->registerDocument($office, $clerk);
        }

        $dashboard = $this->actingAs($admin)
            ->get(route('dashboard'))
            ->viewData('page')['props']['summary'];

        $report = $this->actingAs($admin)
            ->get(route('reports.index'))
            ->viewData('page')['props']['summary'];

        // Both read DocumentStats::summary(). Two screens quoting different
        // totals for the same office is the kind of thing that destroys trust
        // in a reporting system.
        $this->assertSame($report, $dashboard);
    }

    public function test_the_figures_are_office_scoped(): void
    {
        $mine = $this->office('MPDO');
        $admin = $this->admin($mine);
        $this->registerDocument($mine, $this->staff($mine));

        $elsewhere = $this->office('HRMO');
        $this->registerDocument($elsewhere, $this->staff($elsewhere));

        $summary = $this->actingAs($admin)
            ->get(route('dashboard'))
            ->viewData('page')['props']['summary'];

        $this->assertSame(1, $summary['total']);
    }
}
