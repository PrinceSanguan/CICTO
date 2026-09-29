<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Document;
use App\Models\User;
use App\Services\ReportExporter;
use App\Support\Reporting\ActivityReport;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The lists behind the Reports page's "User activity" card (client request,
 * 2026-09-25) -- by document and by person, each row opening onto its trail.
 *
 * JSON, fetched when the card asks: a year of trails for every document an
 * office touched is far too much to put on the page up front, and a trail is
 * only wanted for the one row somebody clicks. Admin and Super Admin only,
 * like the card itself, and every answer is scoped through visibleTo().
 */
class ReportActivityController extends Controller
{
    public function __construct(
        private readonly ActivityReport $activity,
        private readonly ReportExporter $exporter,
    ) {}

    public function documents(Request $request): JsonResponse
    {
        $viewer = $this->viewer($request);

        return response()->json($this->activity->documents(
            $viewer,
            ActivityReport::since(ActivityReport::months($request)),
            $request->string('q')->value(),
            $request->integer('page', 1),
        ));
    }

    public function document(Request $request, Document $document): JsonResponse
    {
        $viewer = $this->viewer($request);

        // 404, not 403: an office that cannot see a document should not learn
        // from this endpoint that its number exists.
        abort_unless($this->activity->canSee($viewer, $document), 404);

        return response()->json([
            'steps' => $this->activity->documentTrail($document),
        ]);
    }

    public function users(Request $request): JsonResponse
    {
        $viewer = $this->viewer($request);

        return response()->json($this->activity->users(
            $viewer,
            ActivityReport::since(ActivityReport::months($request)),
            $request->string('q')->value(),
            $request->integer('page', 1),
        ));
    }

    public function user(Request $request, User $user): JsonResponse
    {
        $viewer = $this->viewer($request);

        return response()->json($this->activity->userTrail(
            $viewer,
            $user,
            ActivityReport::since(ActivityReport::months($request)),
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | Print and export (client request, 2026-09-29)
    |--------------------------------------------------------------------------
    |
    | The card printed only as part of the whole Reports page, and only the
    | ten rows on screen. These give the list -- every page of it, for the
    | period and search on screen -- and any one document's or person's trail
    | a sheet of its own: a printable page, a PDF, Excel or CSV.
    |
    | Scoped exactly as the JSON above, and carrying the same fields: action,
    | person, office and time, never remarks, files or comments.
    |
    */

    public function exportDocuments(Request $request): Response|StreamedResponse
    {
        $viewer = $this->viewer($request);
        $format = $this->format($request);
        $months = ActivityReport::months($request);
        $since = ActivityReport::since($months);
        $search = $this->search($request);

        $this->withinCap($format, $this->activity->documentTotal($viewer, $since, $search));

        return $this->send($format, $viewer, [
            'file' => 'activity-by-document',
            'heading' => 'User activity by document',
            'lines' => array_values(array_filter([
                $this->scope($viewer),
                $this->period($months, $since),
                $search === null ? null : "Matching “{$search}”",
            ])),
            'headings' => ['Last activity', 'Type', 'Control number', 'Title', 'Status', 'Actions'],
            'rows' => $this->activity->eachDocument($viewer, $since, $search)->map(fn (array $row): array => [
                $this->at($row['last_activity_at']),
                $row['type'],
                $row['control_number'],
                $row['title'],
                $row['status'],
                $row['actions'],
            ]),
            'empty' => 'No document activity in this period.',
        ]);
    }

    public function exportDocument(Request $request, Document $document): Response|StreamedResponse
    {
        $viewer = $this->viewer($request);

        // 404 for the same reason as document() above.
        abort_unless($this->activity->canSee($viewer, $document), 404);

        $format = $this->format($request);
        $document->loadMissing('documentType:id,name');

        // The whole trail, as the card shows it: a single document's runs to
        // tens of steps, never near a row cap.
        return $this->send($format, $viewer, [
            'file' => 'activity-'.Str::slug($document->control_number),
            'heading' => $document->documentType->name.' · '.$document->control_number,
            'lines' => [
                $document->title,
                'Status: '.$document->status->publicLabel(),
                'Every step, oldest first',
            ],
            'headings' => ['Date & time', 'Action', 'By', 'From office', 'To / at office'],
            'rows' => array_map(fn (array $step): array => [
                $this->at($step['at']),
                $step['action_label'],
                $step['actor'] ?? 'A removed account',
                $step['from_office'],
                $step['office'],
            ], $this->activity->documentTrail($document)),
            'empty' => 'This document has no recorded steps.',
        ]);
    }

    public function exportUsers(Request $request): Response|StreamedResponse
    {
        $viewer = $this->viewer($request);
        $format = $this->format($request);
        $months = ActivityReport::months($request);
        $since = ActivityReport::since($months);
        $search = $this->search($request);

        $this->withinCap($format, $this->activity->userTotal($viewer, $since, $search));

        return $this->send($format, $viewer, [
            'file' => 'activity-by-user',
            'heading' => 'User activity by user',
            'lines' => array_values(array_filter([
                $this->scope($viewer),
                $this->period($months, $since),
                $search === null ? null : "Matching “{$search}”",
            ])),
            'headings' => ['Last activity', 'Name', 'Office', 'Actions'],
            'rows' => $this->activity->eachUser($viewer, $since, $search)->map(fn (array $row): array => [
                $this->at($row['last_activity_at']),
                $row['name'],
                $row['office'] ?? 'No office',
                $row['actions'],
            ]),
            'empty' => 'No staff activity in this period.',
        ]);
    }

    public function exportUser(Request $request, User $user): Response|StreamedResponse
    {
        $viewer = $this->viewer($request);
        $format = $this->format($request);
        $months = ActivityReport::months($request);
        $since = ActivityReport::since($months);

        $total = $this->activity->userTrailTotal($viewer, $user, $since);

        // Only someone the by-user list would show -- one with activity the
        // viewer may see in the period. The sheet is headed with the person's
        // name and office, and walking the user ids would otherwise hand an
        // office admin the whole staff directory.
        abort_if($total === 0, 404);

        $this->withinCap($format, $total);
        $user->loadMissing('office:id,name');

        return $this->send($format, $viewer, [
            'file' => 'activity-'.Str::slug($user->name),
            'heading' => $user->name,
            'lines' => [
                'Office: '.($user->office->name ?? 'none'),
                $this->scope($viewer),
                $this->period($months, $since).', newest first',
            ],
            'headings' => ['Date & time', 'Action', 'Type', 'Control number', 'Title', 'To / at office'],
            'rows' => $this->activity->eachUserStep($viewer, $user, $since)->map(fn (array $step): array => [
                $this->at($step['at']),
                $step['action_label'],
                $step['document']['type'],
                $step['document']['control_number'],
                $step['document']['title'],
                $step['office'],
            ]),
            'empty' => 'No activity in this period.',
        ]);
    }

    /**
     * One sheet, in the format asked for.
     *
     * `print` is the PDF's own page served as HTML, which opens the browser's
     * print dialog by itself -- one click to paper, and "Save as PDF" there
     * for anyone who wants the file after all.
     *
     * @param  array{file: string, heading: string, lines: list<string>, headings: list<string>, rows: iterable<int, list<scalar|null>>, empty: string}  $sheet
     */
    private function send(string $format, User $viewer, array $sheet): Response|StreamedResponse
    {
        $file = 'cicto-'.$sheet['file'].'-'.now()->format('Ymd-His');
        $generated = 'Generated '.now()->format('m/d/Y g:i A').' by '.$viewer->name;

        if ($format === 'csv') {
            return $this->exporter->csv("{$file}.csv", $sheet['headings'], $sheet['rows']);
        }

        if ($format === 'xlsx') {
            return $this->exporter->xlsx(
                "{$file}.xlsx",
                $sheet['headings'],
                $sheet['rows'],
                [$sheet['heading'], ...$sheet['lines'], $generated],
            );
        }

        $data = [
            'heading' => $sheet['heading'],
            'lines' => $sheet['lines'],
            'generated' => $generated,
            'headings' => $sheet['headings'],
            'rows' => iterator_to_array($sheet['rows'], false),
            'empty' => $sheet['empty'],
            'systemOwner' => config('cicto.support.office'),
            'print' => $format === 'print',
        ];

        return $format === 'pdf'
            ? $this->exporter->pdf('reports.activity', $data, "{$file}.pdf")
            : response()->view('reports.activity', $data);
    }

    private function format(Request $request): string
    {
        $format = $request->query('format');

        return is_string($format) && in_array($format, ['print', 'pdf', 'xlsx', 'csv'], true) ? $format : 'xlsx';
    }

    /**
     * The same row caps as the document register's export, checked before
     * anything is generated. CSV streams and has none -- it is the way out
     * the error names.
     */
    private function withinCap(string $format, int $count): void
    {
        $cap = match ($format) {
            'print', 'pdf' => (int) config('cicto.reports.max_pdf_rows'),
            'xlsx' => (int) config('cicto.reports.max_xlsx_rows'),
            default => PHP_INT_MAX,
        };

        $label = $format === 'print' ? 'printing' : $format;

        abort_if(
            $count > $cap,
            422,
            "This covers {$count} rows, over the {$cap}-row limit for {$label}. Choose a shorter period or search, or use CSV.",
        );
    }

    private function search(Request $request): ?string
    {
        $search = $request->string('q')->trim()->value();

        return $search === '' ? null : $search;
    }

    /**
     * Whose documents these are, worded as the printed Reports page words it:
     * the viewer's office, or every office for a Super Admin.
     */
    private function scope(User $viewer): string
    {
        // Branched on the FK, as ReportController::export does: a Super Admin
        // has no office.
        return 'Covering: '.($viewer->office_id === null ? 'All offices' : $viewer->office->name);
    }

    private function period(int $months, CarbonInterface $since): string
    {
        return 'Last '.$months.' '.Str::plural('month', $months).', from '.$since->format('m/d/Y');
    }

    /** mm/dd/yyyy, as the card shows it (client request, 2026-09-25). */
    private function at(?string $iso): ?string
    {
        return $iso === null ? null : Carbon::parse($iso)->format('m/d/Y g:i A');
    }

    private function viewer(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->atLeast(Role::Admin), 403);

        return $user;
    }
}
