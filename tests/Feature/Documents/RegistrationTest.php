<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\RegisterDocument;
use App\Enums\DocumentPriority;
use App\Enums\DocumentStatus;
use App\Enums\MovementAction;
use App\Models\Document;
use App\Models\DocumentMovement;
use App\Support\QrToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    public function test_registration_allocates_a_sequential_control_number_per_office_and_year(): void
    {
        $office = $this->office('MPDO');
        $user = $this->staff($office);
        $type = $this->documentType();

        $first = $this->registerDocument($office, $user, $type);
        $second = $this->registerDocument($office, $user, $type);

        $year = now()->year;

        $this->assertSame("MPDO-{$year}-00001", $first->control_number);
        $this->assertSame("MPDO-{$year}-00002", $second->control_number);
    }

    public function test_control_numbers_are_scoped_per_office(): void
    {
        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');
        $type = $this->documentType();

        $a = $this->registerDocument($mpdo, $this->staff($mpdo), $type);
        $b = $this->registerDocument($mto, $this->staff($mto), $type);

        $year = now()->year;

        // Each office keeps its own run of numbers -- MTO does not continue MPDO's.
        $this->assertSame("MPDO-{$year}-00001", $a->control_number);
        $this->assertSame("MTO-{$year}-00001", $b->control_number);
    }

    public function test_registration_writes_a_genesis_movement(): void
    {
        $office = $this->office();
        $user = $this->staff($office);

        $document = $this->registerDocument($office, $user);

        $movements = $document->movements()->get();

        $this->assertCount(1, $movements);

        $genesis = $movements->first();
        $this->assertSame(1, $genesis->sequence);
        $this->assertNull($genesis->from_office_id);
        $this->assertSame($office->id, $genesis->to_office_id);
        $this->assertSame(MovementAction::Registered, $genesis->action);
        $this->assertSame(DocumentStatus::Initiated, $genesis->to_status);
        $this->assertNotNull($genesis->arrived_at);
        $this->assertNull($genesis->departed_at);
        $this->assertSame(1, $genesis->is_open);
    }

    public function test_the_qr_token_is_not_derivable_from_the_control_number(): void
    {
        $office = $this->office();
        $document = $this->registerDocument($office, $this->staff($office));

        $this->assertTrue(QrToken::isValid($document->qr_token));
        $this->assertSame(26, mb_strlen($document->qr_token));
        $this->assertStringNotContainsStringIgnoringCase(
            $document->control_number,
            $document->qr_token,
        );
        // Lowercase only: MySQL collation is case-insensitive and PostgreSQL is
        // not, so a mixed-case token would behave differently per driver.
        $this->assertSame(mb_strtolower($document->qr_token), $document->qr_token);
    }

    public function test_qr_tokens_are_unique_across_documents(): void
    {
        $office = $this->office();
        $user = $this->staff($office);
        $type = $this->documentType();

        $tokens = collect(range(1, 5))
            ->map(fn () => $this->registerDocument($office, $user, $type)->qr_token);

        $this->assertCount(5, $tokens->unique());
    }

    public function test_the_due_date_is_stamped_from_the_document_type_turnaround(): void
    {
        $office = $this->office();
        $type = $this->documentType(turnaroundDays: 7);

        $document = $this->registerDocument($office, $this->staff($office), $type);

        $this->assertNotNull($document->due_at);
        $this->assertSame(
            now()->addDays(7)->toDateString(),
            $document->due_at->toDateString(),
        );
    }

    public function test_an_attached_file_becomes_version_one_on_the_private_disk(): void
    {
        Storage::fake('documents');

        $office = $this->office();
        $user = $this->staff($office);

        $document = app(RegisterDocument::class)->handle(
            title: 'With attachment',
            documentTypeId: $this->documentType()->id,
            priority: DocumentPriority::Normal,
            originatingOffice: $office,
            creator: $user,
            upload: UploadedFile::fake()->create('scan.pdf', 120, 'application/pdf'),
        );

        $file = $document->currentFile()->first();

        $this->assertNotNull($file);
        $this->assertSame(1, $file->version);
        $this->assertSame('documents', $file->disk);
        $this->assertSame('scan.pdf', $file->original_name);
        $this->assertSame(64, mb_strlen($file->checksum_sha256));
        // On-disk names are generated ULIDs, never the client filename.
        $this->assertStringNotContainsString('scan.pdf', $file->path);
        Storage::disk('documents')->assertExists($file->path);
    }

    public function test_a_failed_registration_does_not_burn_a_control_number(): void
    {
        $office = $this->office('MPDO');
        $user = $this->staff($office);

        // A non-existent document type makes findOrFail throw inside the
        // registration transaction, so the sequence increment must roll back.
        try {
            app(RegisterDocument::class)->handle(
                title: 'Doomed',
                documentTypeId: 99_999,
                priority: DocumentPriority::Normal,
                originatingOffice: $office,
                creator: $user,
            );
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame(0, Document::query()->count());
        $this->assertSame(0, DocumentMovement::query()->count());

        $next = $this->registerDocument($office, $user);
        $year = now()->year;

        $this->assertSame("MPDO-{$year}-00001", $next->control_number);
    }
}
