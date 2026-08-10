<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Enums\SecurityEventType;
use App\Http\Controllers\Controller;
use App\Models\BackupRun;
use App\Models\SecurityEvent;
use App\Services\Backup\BackupService;
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
