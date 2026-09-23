<?php

namespace App\Console\Commands;

use App\Enums\SecurityEventType;
use App\Models\DocumentSignature;
use App\Models\SecurityEvent;
use Illuminate\Console\Command;

/**
 * What turns "tampering is detectable" into "tampering is detected".
 *
 * The hash binding is worthless on its own -- nobody re-checks a certificate
 * from six months ago. This sweep does it nightly and raises a security event
 * the moment a signed file stops matching, which is the only way anyone finds
 * out before they need the document in a hearing.
 *
 * TWO CHECKS, AND THEY COST WILDLY DIFFERENT AMOUNTS.
 *
 *  - The cheap pair is pure database: recompute the signature's own hash from
 *    its columns, and compare the stored file checksum. Together they catch a
 *    rewritten ROW, and they run at the speed of a query.
 *  - Re-reading the FILE is what catches swapped bytes, and it costs a HEAD
 *    plus a GET per signature. On a local disk that is nothing. On the Cloud
 *    host, where `documents` is object storage, it is two network round trips
 *    each, serially, for every signature that has ever been made.
 *
 * `--max-seconds` exists because of what that second kind costs in an HTTP
 * request. The Super Admin's "Verify signatures" button called this command
 * synchronously with no bound, so the request grew with the register until it
 * passed Cloudflare's limit and the screen showed a 504 (reported 2026-09-23).
 * With a budget, the cheap checks still cover EVERY signature and the byte
 * re-reads cover as many as fit, newest first. The nightly run passes no
 * budget and still re-reads everything.
 */
class VerifySignaturesCommand extends Command
{
    protected $signature = 'cicto:verify-signatures
                            {--quiet-ok : Only report failures}
                            {--max-seconds=0 : Stop re-reading file bytes after this many seconds (0 = no limit); the database checks always cover every signature}';

    protected $description = 'Re-check every signature against the file it was bound to';

    public function handle(): int
    {
        $checked = 0;
        $reread = 0;
        $notReread = 0;
        $broken = [];

        // Float, not int: a test needs to be able to ask for a budget that is
        // already spent by the time the first row arrives, and a whole second
        // is not a bound anything real would use either.
        $maxSeconds = max(0.0, (float) $this->option('max-seconds'));
        $deadline = $maxSeconds > 0.0 ? microtime(true) + $maxSeconds : null;

        /*
         * NEWEST FIRST. Irrelevant to the nightly run, which reads everything
         * either way, and the whole point under a budget: the signatures made
         * this week are the ones somebody is about to rely on.
         */
        DocumentSignature::query()
            ->with(['file', 'document:id,control_number'])
            ->lazyByIdDesc(200)
            ->each(function (DocumentSignature $signature) use (&$checked, &$reread, &$notReread, &$broken, $deadline): void {
                $checked++;

                $withinBudget = $deadline === null || microtime(true) < $deadline;

                // A signature whose file is gone or purged reads nothing from
                // storage, so it neither spends the budget nor counts as
                // covered by it.
                $hasBytes = $signature->document_hash_sha256 !== null
                    && $signature->file !== null
                    && ! $signature->file->isPurged();

                if ($hasBytes) {
                    $withinBudget ? $reread++ : $notReread++;
                }

                $selfOk = $signature->selfHashMatches();
                // rehashBytes: the whole reason this runs nightly rather than
                // on page load. Column comparison catches a rewritten row;
                // only re-reading the file catches swapped bytes.
                $fileOk = $signature->fileHashMatches(rehashBytes: $withinBudget && $hasBytes);

                if ($selfOk && $fileOk) {
                    return;
                }

                $reason = match (true) {
                    ! $selfOk => 'the signature record itself was altered',
                    default => 'the signed file no longer matches its fingerprint',
                };

                $broken[] = [$signature, $reason];
            });

        foreach ($broken as [$signature, $reason]) {
            $label = $signature->document->control_number ?? "document #{$signature->document_id}";

            $this->error("  {$label} · serial {$signature->serial}: {$reason}");

            SecurityEvent::log(
                SecurityEventType::SignatureTampered,
                sprintf('Signature %s on %s failed verification: %s.', $signature->serial, $label, $reason),
                null,
                $label,
            );
        }

        if ($broken === []) {
            if (! $this->option('quiet-ok')) {
                $this->info($this->summary($checked, $reread, $notReread));
            }

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error(sprintf('%d of %d signature(s) FAILED verification.', count($broken), $checked));

        // Non-zero so a cron wrapper or monitor notices.
        return self::FAILURE;
    }

    /**
     * What was actually covered, said plainly.
     *
     * A run that stopped short of re-reading every file must not report the
     * same clean bill of health as one that read them all -- that is the
     * difference between "nothing has been tampered with" and "nothing I
     * looked at has been tampered with".
     */
    private function summary(int $checked, int $reread, int $notReread): string
    {
        $head = "Checked {$checked} signature(s). All match.";

        if ($notReread === 0) {
            return $head;
        }

        return $head." Files re-read from storage: {$reread}; the remaining {$notReread} "
            .'were checked against the database only. The nightly sweep re-reads every one.';
    }
}
