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

    /**
     * The shape the Submit Document form actually posts, at its most ordinary:
     * one department. Every other registration test here uses the scalar
     * `originating_office_id` alias, so without this the common path through
     * the real form -- an `office_ids` list of one -- was untested.
     */
    public function test_submitting_one_department_registers_it_and_queues_nothing(): void
    {
        Storage::fake('documents');

        $office = $this->office('MPDO');

        $this->actingAs($this->staff($office))
            ->post(route('documents.store'), [
                'title' => 'Request for office supplies',
                'document_type_id' => $this->documentType()->id,
                'office_ids' => [$office->id],
                'priority' => 'normal',
                'file' => UploadedFile::fake()->create('request.pdf', 40, 'application/pdf'),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $document = Document::query()->firstOrFail();

        $this->assertSame($office->id, $document->originating_office_id);
        $this->assertStringStartsWith('MPDO-', $document->control_number);
        $this->assertSame(0, $document->routeStops()->count(), 'One department is not a route.');
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

    /**
     * The client's report of 2026-09-19: raising another office above the
     * submitter's own on the Department list registered the document under
     * THAT office, so the uploader showed as one of its users. The form now
     * locks row 1; this is the server refusing the same thing from a tab that
     * predates the lock, or from a hand-built request.
     */
    public function test_the_first_department_must_be_the_submitters_own_office(): void
    {
        Storage::fake('documents');

        $mine = $this->office('MPDO');
        $theirs = $this->office('MTO', 'Treasury');

        $this->actingAs($this->staff($mine))
            ->post(route('documents.store'), [
                'title' => 'Request for office supplies',
                'document_type_id' => $this->documentType()->id,
                'office_ids' => [$theirs->id, $mine->id],
                'priority' => 'normal',
                'file' => UploadedFile::fake()->create('request.pdf', 40, 'application/pdf'),
            ])
            ->assertSessionHasErrors([
                'office_ids' => 'The first department must be your own office, because the document is registered under it.',
            ]);

        $this->assertSame(0, Document::query()->count());
    }

    /** An old single-department client hears about it under the key it posted. */
    public function test_the_origin_rule_answers_the_single_department_alias_too(): void
    {
        Storage::fake('documents');

        $mine = $this->office('MPDO');
        $theirs = $this->office('MTO', 'Treasury');

        $this->actingAs($this->staff($mine))
            ->post(route('documents.store'), [
                'title' => 'Request for office supplies',
                'document_type_id' => $this->documentType()->id,
                'originating_office_id' => $theirs->id,
                'priority' => 'normal',
                'file' => UploadedFile::fake()->create('request.pdf', 40, 'application/pdf'),
            ])
            ->assertSessionHasErrors(['originating_office_id', 'office_ids']);

        $this->assertSame(0, Document::query()->count());
    }

    /**
     * A Super Admin belongs to no office and acts for every one, so whichever
     * office they put first is one they may file under -- the same rule
     * actsForOffice() applies everywhere else.
     */
    public function test_a_super_admin_may_file_under_any_office(): void
    {
        Storage::fake('documents');

        $office = $this->office('MTO', 'Treasury');

        $this->actingAs($this->superAdmin())
            ->post(route('documents.store'), [
                'title' => 'Request for office supplies',
                'document_type_id' => $this->documentType()->id,
                'office_ids' => [$office->id],
                'priority' => 'high',
                'file' => UploadedFile::fake()->create('request.pdf', 40, 'application/pdf'),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($office->id, Document::query()->sole()->originating_office_id);
    }

    /**
     * The client's paper routing slip, word for word, most pressing first --
     * and no pre-selected answer, because the field is required now.
     */
    public function test_the_submit_form_offers_the_clients_three_priorities_in_their_words(): void
    {
        $office = $this->office('MPDO');

        $priorities = $this->actingAs($this->staff($office))
            ->get(route('documents.create'))
            ->assertOk()
            ->viewData('page')['props']['priorities'];

        $this->assertSame([
            ['value' => 'high', 'label' => 'High - Must be done within 24 hours.'],
            ['value' => 'normal', 'label' => 'Medium - Within the week.'],
            ['value' => 'low', 'label' => 'Low - Whenever it is possible.'],
        ], $priorities);
    }

    public function test_priority_is_required_and_urgent_is_no_longer_accepted(): void
    {
        Storage::fake('documents');

        $office = $this->office('MPDO');
        $payload = [
            'title' => 'Request for office supplies',
            'document_type_id' => $this->documentType()->id,
            'office_ids' => [$office->id],
            'file' => UploadedFile::fake()->create('request.pdf', 40, 'application/pdf'),
        ];

        $this->actingAs($this->staff($office))
            ->post(route('documents.store'), $payload)
            ->assertSessionHasErrors(['priority' => 'Please choose a priority.']);

        $this->actingAs($this->staff($office))
            ->post(route('documents.store'), [...$payload, 'priority' => 'urgent'])
            ->assertSessionHasErrors(['priority' => 'Please choose High, Medium or Low.']);

        $this->assertSame(0, Document::query()->count());
    }

    /**
     * Stored values did not change -- `normal` is what the client now calls
     * Medium -- and a document filed as urgent before 2026-09-19 reads High,
     * in High's colour, so the retired word never reaches a screen.
     */
    public function test_priority_labels_use_the_clients_three_words(): void
    {
        $office = $this->office('MPDO');
        $clerk = $this->staff($office);

        $this->registerDocument($office, $clerk, priority: DocumentPriority::Normal);
        $this->registerDocument($office, $clerk, priority: DocumentPriority::Urgent);

        $rows = collect($this->actingAs($clerk)
            ->get(route('documents.index'))
            ->assertOk()
            ->viewData('page')['props']['documents']['data'])
            ->keyBy('priority');

        $this->assertSame('Medium', $rows['normal']['priority_label']);
        $this->assertSame('High', $rows['urgent']['priority_label']);
        $this->assertSame(DocumentPriority::High->tone(), $rows['urgent']['priority_tone']);
    }

    /**
     * A user whose own office was deactivated is offered no row to lock, so the
     * refusal has to tell them what to do, not ask for an office they cannot
     * pick.
     */
    public function test_a_user_whose_office_is_inactive_is_told_why(): void
    {
        Storage::fake('documents');

        $retired = $this->office('MPDO');
        $other = $this->office('MTO', 'Treasury');
        $clerk = $this->staff($retired);
        $retired->forceFill(['is_active' => false])->save();

        $this->actingAs($clerk)
            ->post(route('documents.store'), [
                'title' => 'Request for office supplies',
                'document_type_id' => $this->documentType()->id,
                'office_ids' => [$other->id],
                'priority' => 'normal',
                'file' => UploadedFile::fake()->create('request.pdf', 40, 'application/pdf'),
            ])
            ->assertSessionHasErrors([
                'office_ids' => 'Your office, Planning Office, is no longer active, so documents cannot be registered under it. Ask an administrator to move your account to your current office.',
            ]);

        $this->assertSame(0, Document::query()->count());
    }

    /** A legacy urgent document reads High, so filtering by High finds it. */
    public function test_filtering_by_high_also_finds_legacy_urgent_documents(): void
    {
        $office = $this->office('MPDO');
        $clerk = $this->staff($office);

        $high = $this->registerDocument($office, $clerk, priority: DocumentPriority::High);
        $urgent = $this->registerDocument($office, $clerk, priority: DocumentPriority::Urgent);
        $this->registerDocument($office, $clerk, priority: DocumentPriority::Normal);

        $ids = array_column($this->actingAs($clerk)
            ->get(route('documents.index', ['priority' => 'high']))
            ->assertOk()
            ->viewData('page')['props']['documents']['data'], 'id');

        $this->assertEqualsCanonicalizing([$high->id, $urgent->id], $ids);
    }
}
