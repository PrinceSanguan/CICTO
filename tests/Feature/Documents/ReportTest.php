<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\TransitionDocument;
use App\Enums\MovementAction;
use App\Models\Document;
use App\Models\User;
use App\Support\Reporting\DocumentStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Walk a document all the way to Completed. */
    private function complete(Document $document, User $admin): void
    {
        foreach ([MovementAction::Received, MovementAction::Completed] as $action) {
            $document->refresh();
            app(TransitionDocument::class)->handle(
                document: $document,
                action: $action,
                actor: $admin,
                expectedMovementId: $document->openMovement?->id,
            );
        }
    }

    public function test_monthly_volume_buckets_by_completion_month(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $clerk = $this->staff($office);

        Carbon::setTestNow('2026-06-10 09:00:00');
        $this->complete($this->registerDocument($office, $clerk), $admin);

        Carbon::setTestNow('2026-08-05 09:00:00');
        $this->complete($this->registerDocument($office, $clerk), $admin);
        $this->complete($this->registerDocument($office, $clerk), $admin);

        Carbon::setTestNow('2026-08-20 09:00:00');
        $months = collect(app(DocumentStats::class)->monthlyProcessed($admin, 6))
            ->keyBy('month');

        $this->assertSame(1, $months['2026-06']['count']);
        // Empty months are seeded, not missing -- a chart with gaps reads as
        // missing data rather than as a quiet month.
        $this->assertSame(0, $months['2026-07']['count']);
        $this->assertSame(2, $months['2026-08']['count']);
        $this->assertCount(6, $months);
    }

    public function test_the_approval_rate_is_null_rather_than_zero_when_nothing_is_decided(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $this->registerDocument($office, $this->staff($office));

        $summary = app(DocumentStats::class)->summary($admin);

        // "0%" would read as "everything was rejected".
        $this->assertNull($summary['approval_rate']);
        $this->assertSame(1, $summary['total']);
    }

    public function test_every_report_figure_is_scoped_to_what_the_user_may_see(): void
    {
        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');

        $this->registerDocument($mpdo, $this->staff($mpdo));
        $this->registerDocument($mto, $this->staff($mto));
        $this->registerDocument($mto, $this->staff($mto));

        $stats = app(DocumentStats::class);

        $this->assertSame(1, $stats->summary($this->admin($mpdo))['total']);
        $this->assertSame(2, $stats->summary($this->admin($mto))['total']);
        $this->assertSame(3, $stats->summary($this->superAdmin())['total']);
    }

    public function test_status_distribution_uses_the_client_facing_names(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $this->registerDocument($office, $this->staff($office));

        $rows = collect(app(DocumentStats::class)->statusDistribution($admin))
            ->pluck('count', 'status');

        // §8's four words are acceptance criteria, not internal vocabulary.
        $this->assertEqualsCanonicalizing(
            ['Pending', 'In Process', 'Rejected', 'Completed'],
            $rows->keys()->all(),
        );
        $this->assertSame(1, $rows['Pending']);
    }

    public function test_user_activity_counts_actions_per_person(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $clerk = $this->staff($office);

        $this->complete($this->registerDocument($office, $clerk), $admin);

        $rows = collect(app(DocumentStats::class)->userActivity($admin))->keyBy('user');

        // The clerk registered it; the admin drove two transitions -- received
        // and completed, which is the whole chain now that approval is gone.
        $this->assertSame(1, $rows[$clerk->name]['actions']);
        $this->assertSame(2, $rows[$admin->name]['actions']);

        /*
         * Nobody approves anything any more (the client's 2026-09-03 flow), so
         * the approvals column reads zero on every new document. The column
         * stays: document_movements written before the change still carry
         * `approved`, and this figure is how a report over that period keeps
         * telling the truth about them.
         */
        $this->assertSame(0, $rows[$admin->name]['approvals']);
    }

    public function test_the_reports_page_renders_with_all_four_artifacts(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $this->complete($this->registerDocument($office, $this->staff($office)), $admin);

        $this->actingAs($admin)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->component('reports/index')
                    ->has('summary')
                    ->has('monthlyProcessed')
                    ->has('statusDistribution')
                    ->has('processingTrend')
                    ->has('userActivity')
                    ->where('canExport', true),
            );
    }

    public function test_a_plain_user_cannot_export(): void
    {
        $office = $this->office();

        $this->actingAs($this->staff($office))
            ->get(route('reports.export', ['format' => 'xlsx']))
            ->assertForbidden();
    }

    public function test_the_csv_export_streams_the_register(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $document = $this->registerDocument($office, $this->staff($office));

        $response = $this->actingAs($admin)
            ->get(route('reports.export', ['format' => 'csv']));

        $response->assertOk();
        // Laravel appends the charset.
        $this->assertStringStartsWith('text/csv', (string) $response->headers->get('content-type'));

        $body = $response->streamedContent();
        $this->assertStringContainsString('Control number', $body);
        $this->assertStringContainsString($document->control_number, $body);
    }

    public function test_the_csv_export_streams_past_the_first_chunk(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $clerk = $this->staff($office);

        $seed = $this->registerDocument($office, $clerk);

        // 600 rows: past the 500-row lazyById chunk. The bug only appeared on
        // the SECOND chunk, so every single-document export test passed while
        // any real office's register came out truncated -- with a 200, because
        // the throw happened after the headers were flushed.
        $rows = [];
        $now = now();

        for ($i = 0; $i < 599; $i++) {
            $rows[] = [
                'control_number' => sprintf('BAL-2026-%05d', $i + 1000),
                'title' => 'Bulk document '.$i,
                'document_type_id' => $seed->document_type_id,
                'originating_office_id' => $office->id,
                'created_by_id' => $clerk->id,
                'status' => $seed->status->value,
                'priority' => $seed->priority->value,
                'qr_token' => mb_strtoupper(Str::random(26)),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('documents')->insert($chunk);
        }

        $this->assertSame(600, Document::query()->count());

        $response = $this->actingAs($admin)
            ->get(route('reports.export', ['format' => 'csv']));

        $response->assertOk();

        $body = $response->streamedContent();
        $lines = array_filter(explode("\n", trim($body)));

        // Header plus every document. Anything less is the truncation.
        $this->assertCount(601, $lines);
        $this->assertStringContainsString('BAL-2026-01000', $body);
    }

    public function test_the_xlsx_export_produces_a_real_workbook(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $this->registerDocument($office, $this->staff($office));

        $response = $this->actingAs($admin)
            ->get(route('reports.export', ['format' => 'xlsx']));

        $response->assertOk();

        // XLSX is a zip; "PK" is its magic number. Asserting on the bytes
        // rather than the content-type catches a writer that silently emitted
        // an error page with the right header.
        $this->assertStringStartsWith('PK', $response->streamedContent());
    }

    public function test_an_export_over_the_row_cap_is_refused_before_generating(): void
    {
        config(['cicto.reports.max_xlsx_rows' => 1]);

        $office = $this->office();
        $admin = $this->admin($office);
        $this->registerDocument($office, $this->staff($office));
        $this->registerDocument($office, $this->staff($office));

        // 422 up front beats an out-of-memory 500 halfway through a download.
        $this->actingAs($admin)
            ->get(route('reports.export', ['format' => 'xlsx']))
            ->assertStatus(422);
    }

    public function test_the_pdf_export_renders(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $this->registerDocument($office, $this->staff($office));

        $response = $this->actingAs($admin)
            ->get(route('reports.export', ['format' => 'pdf']));

        $response->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }
}
