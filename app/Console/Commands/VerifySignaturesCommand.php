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
 */
class VerifySignaturesCommand extends Command
{
    protected $signature = 'cicto:verify-signatures {--quiet-ok : Only report failures}';

    protected $description = 'Re-check every signature against the file it was bound to';

    public function handle(): int
    {
        $checked = 0;
        $broken = [];

        DocumentSignature::query()
            ->with(['file', 'document:id,control_number'])
            ->chunkById(200, function ($signatures) use (&$checked, &$broken): void {
                foreach ($signatures as $signature) {
                    $checked++;

                    $selfOk = $signature->selfHashMatches();
                    // rehashBytes: the whole reason this runs nightly rather
                    // than on page load. Column comparison catches a rewritten
                    // row; only re-reading the file catches swapped bytes.
                    $fileOk = $signature->fileHashMatches(rehashBytes: true);

                    if ($selfOk && $fileOk) {
                        continue;
                    }

                    $reason = match (true) {
                        ! $selfOk => 'the signature record itself was altered',
                        default => 'the signed file no longer matches its fingerprint',
                    };

                    $broken[] = [$signature, $reason];
                }
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
                $this->info("Checked {$checked} signature(s). All match.");
            }

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error(sprintf('%d of %d signature(s) FAILED verification.', count($broken), $checked));

        // Non-zero so a cron wrapper or monitor notices.
        return self::FAILURE;
    }
}
