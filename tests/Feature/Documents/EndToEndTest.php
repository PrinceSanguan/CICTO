<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentComment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * The Phase 1/2 demo, driven through HTTP exactly as a clerk would.
 */
class EndToEndTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    public function test_a_clerk_submits_an_admin_routes_it_and_the_next_office_approves_it(): void
    {
        Storage::fake('documents');

        $mpdo = $this->office('MPDO', 'Planning Office');
        $mto = $this->office('MTO', 'Treasury');
        $type = $this->documentType(5);

        $clerk = $this->staff($mpdo);
        $mpdoAdmin = $this->admin($mpdo);
        $mtoAdmin = $this->admin($mto);

        // 1. Submit (§5).
        $this->actingAs($clerk)
            ->post(route('documents.store'), [
                'title' => 'Request for office supplies',
                'description' => 'Bond paper and toner',
                'remarks' => 'Needed before the audit',
                'document_type_id' => $type->id,
                'originating_office_id' => $mpdo->id,
                'priority' => 'high',
                'file' => UploadedFile::fake()->create('request.pdf', 80, 'application/pdf'),
            ])
            ->assertRedirect();

        $document = Document::query()->firstOrFail();
        $this->assertStringStartsWith('MPDO-', $document->control_number);
        $this->assertSame('Pending', $document->status->publicLabel());
        $this->assertNotNull($document->currentFile()->first());

        // 2. The receiving admin picks it up and routes it on (§9).
        $this->actingAs($mpdoAdmin)
            ->post(route('documents.transitions.store', $document), [
                'action' => 'forwarded',
                'to_office_id' => $mto->id,
                'remarks' => 'For funding check',
                'expected_movement_id' => $document->openMovement->id,
            ])
            ->assertRedirect();

        $document->refresh();
        $this->assertSame(DocumentStatus::UnderReview, $document->status);
        $this->assertSame($mto->id, $document->openMovement->to_office_id);

        // 3. The next office approves (§9), which unlocks Send to Another Office.
        $this->actingAs($mtoAdmin)
            ->post(route('documents.transitions.store', $document), [
                'action' => 'approved',
                'remarks' => 'Funds available',
                'expected_movement_id' => $document->openMovement->id,
            ])
            ->assertRedirect();

        $document->refresh();
        $this->assertSame(DocumentStatus::Approved, $document->status);
        $this->assertSame('In Process', $document->status->publicLabel());

        // 4. The trail shows all three hops.
        $this->actingAs($mtoAdmin)
            ->get(route('documents.show', $document))
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->component('documents/show')
                    ->where('document.control_number', $document->control_number)
                    ->has('timeline', 3)
                    ->has('files', 1),
            );
    }

    public function test_rejecting_without_remarks_is_refused(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $document = $this->registerDocument($office, $this->staff($office));

        $this->actingAs($admin)->post(route('documents.transitions.store', $document), [
            'action' => 'received',
            'expected_movement_id' => $document->openMovement->id,
        ]);

        $document->refresh();

        // §9: "approve, reject, or return a document with remarks".
        $this->actingAs($admin)
            ->post(route('documents.transitions.store', $document), [
                'action' => 'rejected',
                'expected_movement_id' => $document->openMovement->id,
            ])
            ->assertSessionHasErrors('remarks');

        $this->assertSame(DocumentStatus::UnderReview, $document->fresh()->status);
    }

    public function test_forwarding_to_the_office_that_already_holds_it_is_refused(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $document = $this->registerDocument($office, $this->staff($office));

        $this->actingAs($admin)
            ->post(route('documents.transitions.store', $document), [
                'action' => 'forwarded',
                'to_office_id' => $office->id,
                'expected_movement_id' => $document->openMovement->id,
            ])
            ->assertSessionHasErrors('to_office_id');
    }

    public function test_an_admin_cannot_approve_their_own_submission_by_default(): void
    {
        config(['cicto.workflow.allow_self_approval' => false]);

        $office = $this->office();
        $admin = $this->admin($office);

        // The admin is both submitter and reviewer -- separation of duties.
        $document = $this->registerDocument($office, $admin);

        $this->actingAs($admin)->post(route('documents.transitions.store', $document), [
            'action' => 'received',
            'expected_movement_id' => $document->openMovement->id,
        ]);

        $document->refresh();

        $this->actingAs($admin)
            ->post(route('documents.transitions.store', $document), [
                'action' => 'approved',
                'remarks' => 'Approving my own request',
                'expected_movement_id' => $document->openMovement->id,
            ])
            ->assertForbidden();
    }

    public function test_self_approval_can_be_switched_on_for_a_small_office(): void
    {
        // Client question A6: in a two-person municipal office the rule above
        // blocks real work, so it is configurable rather than hard-coded.
        config(['cicto.workflow.allow_self_approval' => true]);

        $office = $this->office();
        $admin = $this->admin($office);
        $document = $this->registerDocument($office, $admin);

        $this->actingAs($admin)->post(route('documents.transitions.store', $document), [
            'action' => 'received',
            'expected_movement_id' => $document->openMovement->id,
        ]);

        $document->refresh();

        $this->actingAs($admin)
            ->post(route('documents.transitions.store', $document), [
                'action' => 'approved',
                'remarks' => 'Approved',
                'expected_movement_id' => $document->openMovement->id,
            ])
            ->assertRedirect();

        $this->assertSame(DocumentStatus::Approved, $document->fresh()->status);
    }

    public function test_search_is_case_insensitive_on_the_control_number(): void
    {
        $office = $this->office('MPDO');
        $admin = $this->admin($office);
        $document = $this->registerDocument($office, $this->staff($office));

        // The single test that proves the docs/DATABASE.md lower() rule:
        // MySQL LIKE is case-insensitive and PostgreSQL LIKE is not.
        $this->actingAs($admin)
            ->get(route('documents.index', ['q' => mb_strtolower($document->control_number)]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('documents.data', 1));
    }

    public function test_a_like_wildcard_typed_by_a_clerk_does_not_match_everything(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $this->registerDocument($office, $this->staff($office));

        $this->actingAs($admin)
            ->get(route('documents.index', ['q' => '100%']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('documents.data', 0));
    }

    public function test_a_comment_can_be_added_and_a_decision_remark_cannot_be_edited(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $clerk = $this->staff($office);
        $document = $this->registerDocument($office, $clerk);

        $this->actingAs($clerk)
            ->post(route('documents.comments.store', $document), ['body' => 'Following up on this.'])
            ->assertRedirect();

        $comment = DocumentComment::query()->where('context', DocumentComment::CONTEXT_COMMENT)->firstOrFail();
        $this->assertTrue($comment->isEditable());

        $this->actingAs($clerk)
            ->patch(route('documents.comments.update', [$document, $comment]), ['body' => 'Edited'])
            ->assertRedirect();

        $this->assertSame('Edited', $comment->fresh()->body);
        $this->assertNotNull($comment->fresh()->edited_at);

        // Now a decision remark, which is a ledger entry.
        $this->actingAs($admin)->post(route('documents.transitions.store', $document), [
            'action' => 'received',
            'expected_movement_id' => $document->fresh()->openMovement->id,
        ]);
        $this->actingAs($admin)->post(route('documents.transitions.store', $document), [
            'action' => 'returned',
            'remarks' => 'Please attach the quotation.',
            'expected_movement_id' => $document->fresh()->openMovement->id,
        ]);

        $remark = DocumentComment::query()->where('context', DocumentComment::CONTEXT_RETURN)->firstOrFail();

        $this->actingAs($admin)
            ->patch(route('documents.comments.update', [$document, $remark]), ['body' => 'Rewritten history'])
            ->assertForbidden();
    }
}
