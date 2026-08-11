import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    DatabaseBackup,
    ShieldCheck,
    SlidersHorizontal,
} from 'lucide-react';
import { useState } from 'react';
import { PanelHeading } from '@/components/admin/panel-heading';
import { StatusPill } from '@/components/documents/status-pill';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import documents from '@/routes/documents';
import superAdmin from '@/routes/super-admin';
import type { Tone } from '@/types';

type BackupRow = {
    id: number;
    kind: string;
    status: string;
    status_tone: string;
    driver: string | null;
    size: string | null;
    checksum: string | null;
    started_at: string;
    duration: number | null;
    restored_at: string | null;
    error: string | null;
    triggered_by: string;
    available: boolean;
};

type EventRow = {
    id: number;
    type: string;
    label: string;
    alarming: boolean;
    summary: string;
    actor: string | null;
    subject: string | null;
    ip: string | null;
    at: string | null;
};

type Props = {
    capabilities: {
        proc_open: boolean;
        zip: boolean;
        shell_dumper: boolean;
        driver: string;
        includes_schema: boolean;
        disk: string;
        has_ever_restored: boolean;
    };
    backups: BackupRow[];
    securityEvents: EventRow[];
    retention: {
        scans: number;
        scansEnabled: boolean;
        versions: number;
        versionsEnabled: boolean;
        securityEvents: number;
    };
    pending: {
        id: number;
        control_number: string;
        title: string;
        type: string | null;
        status_label: string;
        status_tone: Tone;
        expected_movement_id: number | null;
        can_approve: boolean;
    }[];
};

export default function SystemSettings({
    capabilities,
    backups,
    securityEvents,
    retention,
    pending,
}: Props) {
    const [restoringId, setRestoringId] = useState<number | null>(null);
    const restore = useForm({ notes: '' });

    return (
        <>
            <Head title="System Settings" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <PanelHeading />

                <Heading
                    title="System Settings"
                    description="Backups, retention and the security log."
                />

                {/*
                    §4's three tiles. They are anchors into the sections below
                    rather than separate screens: every setting they name
                    already lives on this page, and splitting one short page
                    into three would add navigation without adding anything to
                    read.
                */}
                <div className="grid gap-4 sm:grid-cols-3">
                    <Tile
                        href="#security"
                        icon={ShieldCheck}
                        tint="#3B72C4"
                        label="Security Settings"
                    />
                    <Tile
                        href="#preferences"
                        icon={SlidersHorizontal}
                        tint="#7FA398"
                        label="System Preferences"
                    />
                    <Tile
                        href="#backups"
                        icon={DatabaseBackup}
                        tint="#E8B84B"
                        label="Data Backup"
                    />
                </div>

                <SystemControl rows={pending} />

                {/*
                    §22 is "Backup AND Recovery". Until a restore has actually
                    been performed once, the backups are an untested hypothesis
                    — so the banner stays up and says so.
                */}
                {!capabilities.has_ever_restored && (
                    <div className="flex items-start gap-3 rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-900 dark:border-red-500/40 dark:bg-red-500/10 dark:text-red-200">
                        <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                        <div>
                            <strong>No restore has ever been tested.</strong> A
                            backup nobody has restored is a hypothesis, not a
                            recovery plan. Follow the restore runbook against a
                            scratch database, then record the drill below.
                        </div>
                    </div>
                )}

                <section id="backups" className="rounded-xl border p-4">
                    <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <h3 className="text-sm font-semibold">
                            Backups
                            <span className="ml-2 font-normal text-muted-foreground">
                                {capabilities.driver} dumper ·{' '}
                                {capabilities.includes_schema
                                    ? 'includes schema'
                                    : 'data only — migrate before restoring'}
                            </span>
                        </h3>
                        <div className="flex gap-2">
                            <Button
                                size="sm"
                                onClick={() =>
                                    router.post(
                                        superAdmin.settings.backup.url(),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <DatabaseBackup className="size-4" />
                                Run backup now
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        superAdmin.settings.verifySignatures.url(),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <ShieldCheck className="size-4" />
                                Verify signatures
                            </Button>
                        </div>
                    </div>

                    <dl className="mb-4 grid gap-2 text-xs sm:grid-cols-4">
                        <Capability
                            label="proc_open"
                            ok={capabilities.proc_open}
                        />
                        <Capability
                            label="Shell dumper"
                            ok={capabilities.shell_dumper}
                        />
                        <Capability label="ZipArchive" ok={capabilities.zip} />
                        <div className="rounded-md border p-2">
                            <dt className="text-muted-foreground">Disk</dt>
                            <dd className="font-medium">{capabilities.disk}</dd>
                        </div>
                    </dl>

                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Started</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Driver</TableHead>
                                <TableHead>Size</TableHead>
                                <TableHead>By</TableHead>
                                <TableHead>Restore drill</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {backups.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={6}
                                        className="py-8 text-center text-muted-foreground"
                                    >
                                        No backups yet.
                                    </TableCell>
                                </TableRow>
                            )}
                            {backups.map((run) => (
                                <TableRow key={run.id}>
                                    <TableCell className="whitespace-nowrap">
                                        {new Date(
                                            run.started_at,
                                        ).toLocaleString()}
                                    </TableCell>
                                    <TableCell>
                                        <span
                                            className={
                                                run.status === 'failed'
                                                    ? 'text-red-600 dark:text-red-400'
                                                    : run.status === 'completed'
                                                      ? 'text-emerald-700 dark:text-emerald-400'
                                                      : ''
                                            }
                                        >
                                            {run.status}
                                        </span>
                                        {run.error && (
                                            <span className="block max-w-56 truncate text-xs text-muted-foreground">
                                                {run.error}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell>{run.driver ?? '—'}</TableCell>
                                    <TableCell>
                                        {run.size ?? '—'}
                                        {!run.available &&
                                            run.status === 'completed' && (
                                                <span className="block text-xs text-muted-foreground">
                                                    rotated off disk
                                                </span>
                                            )}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {run.triggered_by}
                                    </TableCell>
                                    <TableCell>
                                        {run.restored_at ? (
                                            <span className="text-xs text-emerald-700 dark:text-emerald-400">
                                                {new Date(
                                                    run.restored_at,
                                                ).toLocaleDateString()}
                                            </span>
                                        ) : restoringId === run.id ? (
                                            <form
                                                onSubmit={(event) => {
                                                    event.preventDefault();
                                                    restore.post(
                                                        superAdmin.settings.backup.restored.url(
                                                            { run: run.id },
                                                        ),
                                                        {
                                                            preserveScroll: true,
                                                            onSuccess: () => {
                                                                restore.reset();
                                                                setRestoringId(
                                                                    null,
                                                                );
                                                            },
                                                        },
                                                    );
                                                }}
                                                className="flex gap-1"
                                            >
                                                <Input
                                                    autoFocus
                                                    value={restore.data.notes}
                                                    onChange={(event) =>
                                                        restore.setData(
                                                            'notes',
                                                            event.target.value,
                                                        )
                                                    }
                                                    placeholder="What was restored, and where?"
                                                    className="h-8 text-xs"
                                                />
                                                <Button size="sm" type="submit">
                                                    Save
                                                </Button>
                                            </form>
                                        ) : (
                                            run.status === 'completed' && (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        setRestoringId(run.id)
                                                    }
                                                >
                                                    Record drill
                                                </Button>
                                            )
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </section>

                <section id="preferences" className="rounded-xl border p-4">
                    <h3 className="mb-2 text-sm font-semibold">Retention</h3>
                    <ul className="space-y-1 text-sm text-muted-foreground">
                        <li>
                            Scan records: {retention.scans} days —{' '}
                            {retention.scansEnabled ? (
                                <span className="text-emerald-700 dark:text-emerald-400">
                                    pruning enabled
                                </span>
                            ) : (
                                <span className="text-amber-700 dark:text-amber-400">
                                    pruning disabled, nothing is being deleted
                                </span>
                            )}
                        </li>
                        <li>
                            Intermediate file versions: {retention.versions}{' '}
                            days after completion —{' '}
                            {retention.versionsEnabled ? (
                                <span className="text-emerald-700 dark:text-emerald-400">
                                    pruning enabled
                                </span>
                            ) : (
                                <span className="text-amber-700 dark:text-amber-400">
                                    pruning disabled
                                </span>
                            )}
                        </li>
                        <li>Security log: {retention.securityEvents} days</li>
                    </ul>
                    <p className="mt-2 text-xs text-muted-foreground">
                        Pruning ships disabled. Enable it only once a retention
                        policy is agreed in writing and an off-site backup
                        exists — these are municipal records.
                    </p>
                </section>

                {/* §21 security log */}
                <section id="security" className="rounded-xl border">
                    <h3 className="border-b p-4 text-sm font-semibold">
                        Security log
                        <span className="ml-2 font-normal text-muted-foreground">
                            sign-ins, downloads, role changes
                        </span>
                    </h3>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>When</TableHead>
                                <TableHead>Event</TableHead>
                                <TableHead>Detail</TableHead>
                                <TableHead>IP</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {securityEvents.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={4}
                                        className="py-8 text-center text-muted-foreground"
                                    >
                                        Nothing logged yet.
                                    </TableCell>
                                </TableRow>
                            )}
                            {securityEvents.map((event) => (
                                <TableRow key={event.id}>
                                    <TableCell className="text-xs whitespace-nowrap text-muted-foreground">
                                        {event.at
                                            ? new Date(
                                                  event.at,
                                              ).toLocaleString()
                                            : '—'}
                                    </TableCell>
                                    <TableCell
                                        className={
                                            event.alarming
                                                ? 'font-medium text-red-600 dark:text-red-400'
                                                : ''
                                        }
                                    >
                                        {event.label}
                                    </TableCell>
                                    <TableCell className="max-w-96 truncate">
                                        {event.summary}
                                    </TableCell>
                                    <TableCell className="font-mono text-xs text-muted-foreground">
                                        {event.ip ?? '—'}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </section>
            </div>
        </>
    );
}

/** One of §4's three settings tiles: an anchor into the section below. */
function Tile({
    href,
    icon: Icon,
    tint,
    label,
}: {
    href: string;
    icon: typeof ShieldCheck;
    tint: string;
    label: string;
}) {
    return (
        <a
            href={href}
            className="flex flex-col items-center gap-3 rounded-xl border p-4 text-center no-underline transition hover:bg-muted/50"
        >
            <span
                aria-hidden="true"
                className="flex size-20 items-center justify-center rounded-lg text-white"
                style={{ backgroundColor: tint }}
            >
                <Icon className="size-10" strokeWidth={1.5} />
            </span>
            <span className="text-sm font-semibold">{label}</span>
        </a>
    );
}

/**
 * §4's System Control card.
 *
 * Approve posts to the same guarded endpoint the document page uses, carrying
 * the open leg it was rendered against -- so a stale page here is refused with
 * the same conflict message rather than silently acting on a document that has
 * already moved. The button only appears where the workflow and the policy
 * both allow it, which is why some rows show only a link.
 */
function SystemControl({ rows }: { rows: Props['pending'] }) {
    return (
        <section className="rounded-xl border p-4">
            <h3 className="mb-3 text-sm font-semibold">
                System Control
                <span className="ml-2 font-normal text-muted-foreground">
                    pending documents across every office
                </span>
            </h3>

            {rows.length === 0 && (
                <p className="py-6 text-center text-sm text-muted-foreground">
                    Nothing is waiting anywhere in the building.
                </p>
            )}

            <ul className="space-y-2">
                {rows.map((row) => (
                    <li
                        key={row.id}
                        className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3"
                    >
                        <div className="min-w-0">
                            <Link
                                href={documents.show(row.id)}
                                className="font-semibold hover:underline"
                            >
                                {row.title}
                            </Link>
                            <p className="font-mono text-xs text-muted-foreground">
                                {row.control_number}
                                {row.type && ` · ${row.type}`}
                            </p>
                        </div>

                        <div className="flex items-center gap-2">
                            <StatusPill
                                tone={row.status_tone}
                                label={row.status_label}
                                className="min-w-0 px-3 py-1 text-xs"
                            />

                            {row.can_approve ? (
                                <Button
                                    size="sm"
                                    onClick={() =>
                                        router.post(
                                            documents.transitions.store.url(
                                                row.id,
                                            ),
                                            {
                                                action: 'approved',
                                                expected_movement_id:
                                                    row.expected_movement_id,
                                            },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Approve
                                </Button>
                            ) : (
                                <Button size="sm" variant="outline" asChild>
                                    <Link href={documents.show(row.id)}>
                                        Review
                                    </Link>
                                </Button>
                            )}
                        </div>
                    </li>
                ))}
            </ul>
        </section>
    );
}

function Capability({ label, ok }: { label: string; ok: boolean }) {
    return (
        <div className="rounded-md border p-2">
            <dt className="text-muted-foreground">{label}</dt>
            <dd
                className={
                    ok
                        ? 'font-medium text-emerald-700 dark:text-emerald-400'
                        : 'font-medium text-amber-700 dark:text-amber-400'
                }
            >
                {ok ? 'available' : 'unavailable'}
            </dd>
        </div>
    );
}

SystemSettings.layout = {
    breadcrumbs: [
        { title: 'System Settings', href: superAdmin.settings.edit() },
    ],
};
