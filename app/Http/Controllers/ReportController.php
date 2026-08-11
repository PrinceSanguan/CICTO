<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Document;
use App\Services\ReportExporter;
use App\Support\Reporting\DocumentStats;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * §19 Reports and Analytics.
 *
 * Every figure on this page is scoped by visibleTo($user): a User sees their
 * own submissions, an Admin their office, a Super Admin everything. The scoping
 * lives in the stats class, not here, so an export cannot accidentally take a
 * different path from the screen it was launched from.
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly DocumentStats $stats,
        private readonly ReportExporter $exporter,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();
        $months = $this->months($request);

        return Inertia::render('reports/index', [
            'summary' => $this->stats->summary($user),
            'monthlyProcessed' => $this->stats->monthlyProcessed($user, $months),
            'monthlyByStatus' => $this->stats->monthlyByStatus($user, $months),
            'statusDistribution' => $this->stats->statusDistribution($user),
            'processingTrend' => $this->stats->processingTrend($user, $months),
            'userActivity' => $user->atLeast(Role::Admin)
                ? $this->stats->userActivity($user)
                : [],
            'officePerformance' => $user->atLeast(Role::Admin)
                ? $this->stats->officePerformance($user)
                : [],
            'months' => $months,
            'canExport' => $user->atLeast(Role::Admin),
            'limits' => [
                'pdf' => (int) config('cicto.reports.max_pdf_rows'),
                'xlsx' => (int) config('cicto.reports.max_xlsx_rows'),
            ],
        ]);
    }

    /**
     * The document register, exported.
     *
     * The row cap is checked BEFORE anything is generated and returns 422 --
     * an out-of-memory 500 halfway through a download leaves the user with a
     * truncated file and no explanation.
     */
    public function export(Request $request): Response|StreamedResponse
    {
        $user = $request->user();

        abort_unless($user->atLeast(Role::Admin), 403);

        $format = in_array($request->query('format'), ['pdf', 'xlsx', 'csv'], true)
            ? (string) $request->query('format')
            : 'xlsx';

        $query = Document::query()
            ->visibleTo($user)
            ->with(['documentType:id,name', 'originatingOffice:id,name', 'openMovement.toOffice:id,name']);

        $count = (clone $query)->count();

        // CSV streams row by row and cannot exhaust memory, so it is the escape
        // hatch the UI points at when a range is too big for the other two.
        // Capping it at the Excel limit would close the only exit.
        $cap = match ($format) {
            'pdf' => (int) config('cicto.reports.max_pdf_rows'),
            'csv' => PHP_INT_MAX,
            default => (int) config('cicto.reports.max_xlsx_rows'),
        };

        abort_if(
            $count > $cap,
            422,
            "This export covers {$count} documents, over the {$cap}-row limit for {$format}. Narrow the range and try again.",
        );

        $stamp = now()->format('Ymd-His');

        if ($format === 'pdf') {
            return $this->exporter->pdf(
                'reports.document-register',
                [
                    'documents' => (clone $query)
                        ->orderByDesc('documents.created_at')
                        ->orderByDesc('documents.id')
                        ->get(),
                    'summary' => $this->stats->summary($user),
                    'generatedFor' => $user,
                    'office' => config('cicto.support.office'),
                ],
                "cicto-documents-{$stamp}.pdf",
            );
        }

        $headings = ['Control number', 'Title', 'Type', 'Status', 'Priority', 'Originating office', 'Currently at', 'Registered', 'Completed'];

        // A generator so the writer streams and the whole register never sits
        // in memory at once.
        //
        // lazyById takes over the ordering (it must, to page by key), and it
        // reads the cursor back off each model by ATTRIBUTE name -- so the
        // qualified column needs an explicit `id` alias or every batch after
        // the first looks up a property that does not exist. Rows come out in
        // id order; the register is a list, not a ranking.
        $rows = (function () use ($query) {
            foreach ($query->lazyById(500, 'documents.id', 'id') as $document) {
                yield [
                    $document->control_number,
                    $document->title,
                    $document->documentType?->name,
                    $document->status->publicLabel(),
                    $document->priority->label(),
                    $document->originatingOffice?->name,
                    $document->openMovement?->toOffice?->name,
                    $document->created_at?->format('Y-m-d H:i'),
                    $document->completed_at?->format('Y-m-d H:i'),
                ];
            }
        })();

        return $format === 'csv'
            ? $this->exporter->csv("cicto-documents-{$stamp}.csv", $headings, $rows)
            : $this->exporter->xlsx("cicto-documents-{$stamp}.xlsx", $headings, $rows);
    }

    private function months(Request $request): int
    {
        $months = (int) $request->query('months', (string) config('cicto.reports.default_months', 12));

        return max(1, min(36, $months));
    }
}
