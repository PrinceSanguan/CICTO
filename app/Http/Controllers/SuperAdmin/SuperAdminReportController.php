<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Enums\Role;
use App\Enums\SecurityEventType;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentFile;
use App\Models\DocumentMovement;
use App\Models\SecurityEvent;
use App\Support\Reporting\AdminTrend;
use App\Support\Reporting\MonthlySeries;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §4's Reports & Analytics screen for the Super Admin panel.
 *
 * Three charts, all drawn from records the system already keeps: the workflow
 * trend from documents, and user activity from the security event log, which
 * exists for §21 and is written on every sign-in.
 *
 * Nothing here is sampled or estimated. A dashboard that shows a plausible
 * shape rather than the real one is worse than no dashboard, because somebody
 * will eventually make a staffing decision from it.
 */
class SuperAdminReportController extends Controller
{
    public function __construct(
        private readonly AdminTrend $trend,
        private readonly MonthlySeries $series,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('super-admin/reports/index', [
            // visibleTo is still applied, and for a Super Admin it is a no-op
            // by design -- the scoping lives in one place rather than being
            // skipped here on the assumption this role sees everything.
            'trend' => $this->trend->monthly($user),
            'activity' => $this->activity(),
            'processing' => $this->processing($user),
        ]);
    }

    /**
     * User logins, document uploads and admin logins per month.
     *
     * Admin logins are counted by joining the event back to the account's
     * CURRENT role, which is the honest caveat to record: an account promoted
     * to Admin last week has its older sign-ins counted as admin logins too.
     * The alternative -- stamping the role onto every event row -- would mean
     * a schema change to the security log for a chart.
     *
     * @return list<array<string, mixed>>
     */
    private function activity(): array
    {
        $logins = SecurityEvent::query()
            ->where('type', SecurityEventType::LoginSucceeded->value);

        $adminLogins = SecurityEvent::query()
            ->where('security_events.type', SecurityEventType::LoginSucceeded->value)
            ->join('users', 'users.id', '=', 'security_events.user_id')
            ->whereIn('users.role', [Role::Admin->value, Role::SuperAdmin->value]);

        return $this->series->combine([
            'user_logins' => $this->series->monthly($logins, 'security_events.created_at'),
            'document_uploads' => $this->series->monthly(
                DocumentFile::query(),
                'document_files.created_at',
            ),
            'admin_logins' => $this->series->monthly($adminLogins, 'security_events.created_at'),
        ]);
    }

    /**
     * New, approved and rejected documents per month.
     *
     * Distinct from the trend above: that one buckets a document by its
     * CURRENT status, so a document approved in March and completed in April
     * counts once. This one counts events -- when it arrived, when it was
     * approved -- which is what "processing trend" means to somebody asking how
     * much work the building got through.
     *
     * @return list<array<string, mixed>>
     */
    private function processing(mixed $user): array
    {
        return $this->series->combine([
            'new' => $this->series->monthly(
                Document::query()->visibleTo($user),
                'documents.created_at',
            ),
            'approved' => $this->series->monthly(
                DocumentMovement::query()
                    ->where('document_movements.to_status', 'approved'),
                'document_movements.created_at',
            ),
            'rejected' => $this->series->monthly(
                DocumentMovement::query()
                    ->where('document_movements.to_status', 'rejected'),
                'document_movements.created_at',
            ),
        ]);
    }
}
