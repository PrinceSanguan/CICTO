<?php

namespace Tests\Feature\SuperAdmin;

use App\Actions\Documents\RegisterDocument;
use App\Actions\Documents\SignDocument;
use App\Actions\Documents\TransitionDocument;
use App\Enums\DocumentPriority;
use App\Enums\MovementAction;
use App\Enums\SecurityEventType;
use App\Enums\SignatureMethod;
use App\Models\Document;
use App\Models\DocumentFile;
use App\Models\DocumentSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * The Super Admin's "Verify signatures" button.
 *
 * It answered a Cloudflare 504 on 2026-09-23. The command it calls re-reads
 * every signed file from storage to catch swapped bytes -- a HEAD and a GET
 * each -- and on the Cloud host `documents` is object storage, so that is two
 * network round trips per signature, serially, inside one HTTP request. The
 * register only grows, so the button was always going to run past the proxy's
 * limit eventually; it did.
 *
 * What these tests pin down is the shape of the fix: the request is BOUNDED,
 * the database checks still cover EVERY signature whatever the bound, and a
 * run that skipped file reads says so instead of reporting the same clean bill
 * of health as a full one.
 */
class VerifySignaturesTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    /** A document sitting with its office, signed, with a real file behind it. */
    private function signed(string $content = '%PDF-1.4 original'): DocumentSignature
    {
        Storage::fake('documents');

        $office = $this->office('MPDO');
        $admin = $this->admin($office);

        $document = app(RegisterDocument::class)->handle(
            title: 'Purchase request',
            documentTypeId: $this->documentType()->id,
            priority: DocumentPriority::Normal,
            originatingOffice: $office,
            creator: $this->staff($office),
            upload: UploadedFile::fake()->createWithContent('pr.pdf', $content),
        );

        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Received,
            actor: $admin,
            expectedMovementId: $document->openMovement->id,
        );

        return app(SignDocument::class)->handle($document->refresh(), $admin, SignatureMethod::Typed);
    }

    public function test_the_button_answers_with_a_result_and_records_who_pressed_it(): void
    {
        $this->signed();

        $this->actingAs($this->superAdmin())
            ->post(route('super-admin.settings.verify-signatures'))
            ->assertRedirect()
            ->assertSessionHas('toast.type', 'success')
            // The command's own summary, not a hardcoded reassurance: what the
            // Super Admin reads has to describe the run that just happened.
            ->assertSessionHas('toast.message', fn (string $message): bool => str_contains($message, 'Checked 1 signature(s)'));

        $this->assertDatabaseHas('security_events', [
            'type' => SecurityEventType::SettingChanged->value,
            'summary' => 'Signature verification run manually.',
        ]);
    }

    /**
     * The 504 itself, as close as a test can get to it.
     *
     * The failure was unbounded storage reads inside the request, so what has
     * to hold is that the manual run always carries a time budget. Without
     * this, removing the option is a silent return to a button that times out
     * on a register big enough -- which is to say, later, in production.
     */
    public function test_the_manual_run_is_bounded_so_the_request_cannot_run_forever(): void
    {
        Artisan::shouldReceive('call')
            ->once()
            ->withArgs(function (string $command, array $parameters): bool {
                return $command === 'cicto:verify-signatures'
                    && isset($parameters['--max-seconds'])
                    && (float) $parameters['--max-seconds'] > 0;
            })
            ->andReturn(0);

        Artisan::shouldReceive('output')->andReturn('Checked 0 signature(s). All match.');

        $this->actingAs($this->superAdmin())
            ->post(route('super-admin.settings.verify-signatures'))
            ->assertRedirect()
            ->assertSessionHas('toast.type', 'success');
    }

    /**
     * The budget caps file reads, never the database checks -- otherwise a
     * bounded run would be worth nothing at all on a large register.
     */
    public function test_an_altered_record_is_caught_even_when_the_budget_is_already_spent(): void
    {
        $signature = $this->signed();

        DocumentSignature::query()
            ->whereKey($signature->id)
            ->update(['signer_name' => 'Someone Else']);

        $this->artisan('cicto:verify-signatures --max-seconds=0.000001')->assertFailed();

        $this->assertDatabaseHas('security_events', [
            'type' => SecurityEventType::SignatureTampered->value,
        ]);
    }

    /** Same for a rewritten checksum column: caught without reading a byte. */
    public function test_a_rewritten_checksum_is_caught_when_the_budget_is_already_spent(): void
    {
        $signature = $this->signed();

        DocumentFile::query()
            ->whereKey($signature->document_file_id)
            ->update(['checksum_sha256' => str_repeat('b', 64)]);

        $this->artisan('cicto:verify-signatures --max-seconds=0.000001')->assertFailed();
    }

    /**
     * What the budget genuinely gives up, stated by the run itself.
     *
     * Swapped bytes under an intact checksum column are invisible without
     * re-reading the file. A bounded run can miss that, so it must not report
     * a clean bill of health as though it had looked.
     */
    public function test_a_run_that_skipped_file_reads_says_so_instead_of_claiming_all_clear(): void
    {
        $signature = $this->signed();

        // Bytes swapped, checksum column left alone: only a re-read finds it.
        $file = $signature->file;
        Storage::disk($file->disk)->put($file->path, '%PDF-1.4 TAMPERED');

        // One expectation, not three: Laravel matches each expectsOutputToContain
        // against a separate write, and the whole summary is one line.
        $this->artisan('cicto:verify-signatures --max-seconds=0.000001')
            ->expectsOutputToContain(
                'Checked 1 signature(s). All match. Files re-read from storage: 0; '
                .'the remaining 1 were checked against the database only.'
            )
            ->assertSuccessful();

        // The nightly run, which has no HTTP client waiting on it, still reads
        // every file -- and catches exactly what the bounded one could not.
        $this->artisan('cicto:verify-signatures')->assertFailed();
    }

    public function test_an_unbounded_run_reports_no_caveat(): void
    {
        $this->signed();

        $this->artisan('cicto:verify-signatures')
            ->expectsOutputToContain('All match.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('security_events', [
            'type' => SecurityEventType::SignatureTampered->value,
        ]);
    }

    /** A signature made before anything was attached still verifies. */
    public function test_a_document_with_no_file_does_not_spend_the_budget(): void
    {
        $office = $this->office('HRMO');
        $admin = $this->admin($office);

        $document = Document::query()->find(
            $this->registerDocument($office, $this->staff($office))->id,
        );

        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Received,
            actor: $admin,
            expectedMovementId: $document->openMovement->id,
        );

        app(SignDocument::class)->handle($document->refresh(), $admin, SignatureMethod::Typed);

        $this->artisan('cicto:verify-signatures --max-seconds=0.000001')
            ->doesntExpectOutputToContain('checked against the database only')
            ->assertSuccessful();
    }
}
