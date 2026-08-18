<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Enums\MovementAction;
use App\Enums\SecurityEventType;
use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\BackupRun;
use App\Models\Document;
use App\Models\SecurityEvent;
use App\Services\Backup\BackupService;
use App\Support\DocumentWorkflow;
use App\Support\Reporting\AdminTrend;
use App\Support\SystemSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * §2 "Super Admin ... configures system settings", plus the §22 backup console
 * and the §21 security log.
 */
class SystemController extends Controller
{
    public function __construct(private readonly BackupService $backups) {}

    public function index(Request $request): Response
    {
        return Inertia::render('super-admin/settings/index', [
            'capabilities' => $this->backups->capabilities(),

            'backups' => BackupRun::query()
                ->with('triggeredBy:id,name')
                ->orderByDesc('started_at')
                ->limit(20)
                ->get()
                ->map(fn (BackupRun $run) => [
                    'id' => $run->id,
                    'kind' => $run->kind,
                    'status' => $run->status->value,
                    'status_tone' => $run->status->tone(),
                    'driver' => $run->driver,
                    'size' => $run->humanSize(),
                    'checksum' => $run->checksum_sha256 === null ? null : substr($run->checksum_sha256, 0, 16),
                    'started_at' => $run->started_at->toIso8601String(),
                    'duration' => $run->durationSeconds(),
                    'restored_at' => $run->restored_at?->toIso8601String(),
                    'error' => $run->error,
                    'triggered_by' => $run->triggeredBy->name ?? 'Scheduler',
                    'available' => $run->exists_on_disk(),
                ])
                ->all(),

            // §21: the security log. Read-only and Super Admin only -- it
            // contains failed sign-in attempts and IP addresses.
            /*
             * §4's System Control card.
             *
             * The design puts an Approve button beside each pending document.
             * It is only rendered where approval is genuinely legal from that
             * document's current state AND permitted for this viewer -- the
             * same DocumentWorkflow map and the same policy that guard the
             * document page, so this shortcut cannot do anything the long way
             * round would refuse.
             */
            'pending' => Document::query()
                ->active()
                ->with(['documentType:id,name', 'openMovement:id,document_id'])
                ->whereIn('documents.status', AdminTrend::PENDING_STATES)
                ->orderByDesc('documents.updated_at')
                ->limit(8)
                ->get()
                ->map(fn (Document $document) => [
                    'id' => $document->id,
                    'control_number' => $document->control_number,
                    'title' => $document->title,
                    'type' => $document->documentType?->name,
                    'status_label' => $document->status->publicLabel(),
                    'status_tone' => $document->status->publicTone(),
                    'expected_movement_id' => $document->openMovement?->id,
                    'can_approve' => in_array(
                        MovementAction::Approved,
                        DocumentWorkflow::allowed($document->status),
                        true,
                    ) && $request->user()->can('act', [$document, MovementAction::Approved]),
                ])
                ->all(),

            'securityEvents' => SecurityEvent::query()
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(fn (SecurityEvent $event) => [
                    'id' => $event->id,
                    'type' => $event->type->value,
                    'label' => $event->type->label(),
                    'alarming' => $event->type->isAlarming(),
                    'summary' => $event->summary,
                    'actor' => $event->actor_label,
                    'subject' => $event->subject_label,
                    'ip' => $event->ip_address,
                    'at' => $event->created_at?->toIso8601String(),
                ])
                ->all(),

            'retention' => [
                'scans' => (int) config('cicto.scans.retention_days'),
                'scansEnabled' => (bool) config('cicto.scans.pruning_enabled'),
                'versions' => (int) config('cicto.retention.versions.after_days'),
                'versionsEnabled' => (bool) config('cicto.retention.versions.enabled'),
                'securityEvents' => (int) config('cicto.retention.security_events.after_days'),
            ],

            /*
             * Client question A6, made the client's decision rather than ours.
             *
             * `chosen` is reported separately from `allowSelfApproval` because
             * "off because nobody has decided" and "off because the LGU decided
             * off" look identical on screen and are not the same thing. The
             * screen says which it is.
             */
            'workflow' => [
                'allowSelfApproval' => SystemSettings::allowSelfApproval(),
                'chosen' => SystemSettings::selfApprovalWasChosen(),
            ],
        ]);
    }

    /**
     * §2 "Super Admin ... configures system settings", for the one setting the
     * client asked to be able to change themselves.
     *
     * Stored in app_settings rather than written back to .env: a setting that
     * needs a deployment to change is not a setting the client controls, which
     * is what "they can allow or block it" asks for. config/cicto.php stays the
     * boot default for a fresh installation.
     */
    public function updateWorkflow(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'allow_self_approval' => ['required', 'boolean'],
        ]);

        $allow = (bool) $validated['allow_self_approval'];

        // Read before writing, so the security log records transitions rather
        // than clicks. A double-tap on the checkbox is not a change of policy,
        // and a §21 log padded with identical entries is one nobody reads when
        // a real change needs finding.
        $changed = SystemSettings::allowSelfApproval() !== $allow
            || ! SystemSettings::selfApprovalWasChosen();

        /*
         * The 'bool' third argument is load-bearing. AppSetting::put casts the
         * value to a string before encrypting it, so false becomes '' -- and
         * with the default value_type of 'string', typedValue() would hand back
         * '' rather than false. '' is not null, so AppSetting::get would not
         * fall through to the config default either, and the setting would read
         * as an empty string forever.
         */
        AppSetting::put(
            SystemSettings::ALLOW_SELF_APPROVAL,
            $allow,
            'bool',
            'workflow',
            false,
            'Allow self-approval',
        );

        if ($changed) {
            SecurityEvent::log(
                SecurityEventType::SettingChanged,
                $allow
                    ? 'Self-approval turned ON: an office head may now approve and sign their own submissions.'
                    : 'Self-approval turned OFF: an office head may no longer approve or sign their own submissions.',
                $request->user(),
            );
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => $allow
                ? 'Office heads can now approve their own documents.'
                : 'Office heads can no longer approve their own documents.',
        ]);
    }

    /**
     * §22 "Run Backup Now".
     *
     * Synchronous: there is no queue worker, so dispatching this would mean the
     * button appears to work and nothing ever happens.
     */
    public function backup(Request $request): RedirectResponse
    {
        try {
            $run = $this->backups->run($request->user());
        } catch (Throwable $e) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'Backup failed: '.mb_substr($e->getMessage(), 0, 160),
            ]);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => "Backup complete ({$run->humanSize()}, {$run->driver} dumper).",
        ]);
    }

    /**
     * Record that a restore drill actually happened.
     *
     * Deliberately a manual attestation rather than an automated restore: this
     * application must never be able to overwrite its own live database from a
     * web request. The runbook is in
     * docs/implementation/phase-3-trust-and-toolchain.md.
     */
    public function recordRestore(Request $request, BackupRun $run): RedirectResponse
    {
        $validated = $request->validate([
            'notes' => ['required', 'string', 'max:2000'],
        ]);

        $this->backups->recordRestore($run, $request->user(), $validated['notes']);

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Restore drill recorded.',
        ]);
    }

    /** Verify every signature on demand, rather than waiting for the sweep. */
    public function verifySignatures(Request $request): RedirectResponse
    {
        $exit = Artisan::call('cicto:verify-signatures');

        SecurityEvent::log(
            SecurityEventType::SettingChanged,
            'Signature verification run manually.',
            $request->user(),
        );

        return back()->with('toast', [
            'type' => $exit === 0 ? 'success' : 'error',
            'message' => $exit === 0
                ? 'All signatures match their recorded fingerprints.'
                : 'One or more signatures FAILED verification. See the security log.',
        ]);
    }
}
