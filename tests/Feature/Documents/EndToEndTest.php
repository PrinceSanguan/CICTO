<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentStatus;
use App\Enums\NotificationType;
use App\Enums\RouteStopStatus;
use App\Models\Document;
use App\Models\DocumentComment;
use App\Models\Notification;
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

    public function test_a_clerk_submits_an_admin_routes_it_and_the_next_office_receives_it(): void
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

        // 3. The next office acknowledges it. That is the whole of what an
        //    office does now -- no approval to wait on, and Send to Another
        //    Office stays available either way.
        $this->actingAs($mtoAdmin)
            ->post(route('documents.transitions.store', $document), [
                'action' => 'received',
                'remarks' => 'Funds available',
                'expected_movement_id' => $document->openMovement->id,
            ])
            ->assertRedirect();

        $document->refresh();
        $this->assertSame(DocumentStatus::UnderReview, $document->status);
        $this->assertSame('In Process', $document->status->publicLabel());
        $this->assertSame(
            $mto->id,
            $document->openMovement->to_office_id,
            'Nothing was queued behind MTO, so the folder stays with them.',
        );

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

    /**
     * §9 return, which replaced reject on 2026-09-15, driven exactly as the
     * page drives it.
     *
     * The client's words: make the button "return", let the correct document be
     * uploaded, "para po yung document history hindi maputol ... para isang qr
     * code na lang din po yung magamit nung isang document". So the whole
     * behaviour in one pass, because every part of it is a claim the client
     * will check: Return is offered and Reject is not, the reason is
     * compulsory, the folder goes back to the office that filed it with the
     * rest of the route still waiting, the submitter is told, the corrected
     * file is uploaded as the next version in the same submit as Resubmit, the
     * folder goes back to the office that returned it, the route then carries
     * on to the end -- and it is ONE document throughout, with one control
     * number, one QR token and one unbroken trail.
     */
    public function test_a_returned_document_is_corrected_and_carries_on_as_the_same_document(): void
    {
        Storage::fake('documents');

        $mpdo = $this->office('MPDO', 'Planning Office');
        $mto = $this->office('MTO', 'Treasury');
        $hrmo = $this->office('HRMO', 'Human Resource');

        $clerk = $this->staff($mpdo);
        $mtoAdmin = $this->admin($mto);
        $hrmoAdmin = $this->admin($hrmo);

        // Filed at MPDO, routed onward through MTO and then HRMO.
        $this->actingAs($clerk)
            ->post(route('documents.store'), [
                'title' => 'Request for office supplies',
                'document_type_id' => $this->documentType()->id,
                'office_ids' => [$mpdo->id, $mto->id, $hrmo->id],
                'priority' => 'normal',
                'file' => UploadedFile::fake()->create('request.pdf', 40, 'application/pdf'),
            ])
            ->assertSessionHasNoErrors();

        $document = Document::query()->firstOrFail();

        // Move it to MTO so an office other than the submitter's holds it.
        $this->actingAs($this->admin($mpdo))
            ->post(route('documents.transitions.store', $document), [
                'action' => 'received',
                'expected_movement_id' => $document->openMovement->id,
            ])
            ->assertRedirect();

        $document->refresh();
        $this->assertSame($mto->id, $document->openMovement->to_office_id);

        $controlNumber = $document->control_number;
        $qrToken = $document->qr_token;

        // Return is on the page for the office holding it, and Reject is gone.
        $offered = array_column(
            $this->actingAs($mtoAdmin)
                ->get(route('documents.show', $document))
                ->assertOk()
                ->viewData('page')['props']['document']['available_actions'],
            'value',
        );

        $this->assertContains('returned', $offered);
        $this->assertNotContains('rejected', $offered);

        // §9: "return ... with remarks". No reason, no return.
        $this->actingAs($mtoAdmin)
            ->post(route('documents.transitions.store', $document), [
                'action' => 'returned',
                'expected_movement_id' => $document->openMovement->id,
            ])
            ->assertSessionHasErrors('remarks');

        $this->assertSame(
            DocumentStatus::UnderReview,
            $document->fresh()->status,
            'A refused submit must not have moved the document.',
        );

        $this->actingAs($mtoAdmin)
            ->post(route('documents.transitions.store', $document), [
                'action' => 'returned',
                'remarks' => 'The attached quotation is unsigned.',
                // Exactly what the page posts for an empty array in form state.
                'to_office_ids' => [''],
                'expected_movement_id' => $document->fresh()->openMovement->id,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $document->refresh();

        $this->assertSame(DocumentStatus::Returned, $document->status);
        $this->assertSame(
            $mpdo->id,
            $document->openMovement->to_office_id,
            'A returned document goes back to the office that filed it.',
        );

        // The route waits rather than dying: HRMO is still queued behind MTO.
        $this->assertSame(1, $document->routeStops()->where('status', RouteStopStatus::Pending)->count());
        $this->assertSame(0, $document->routeStops()->where('status', RouteStopStatus::Cancelled)->count());

        // The reason is in the trail AND mirrored into the panel, and the
        // mirror is not editable -- CONTEXT_RETURN, not CONTEXT_COMMENT.
        $remark = DocumentComment::query()
            ->where('context', DocumentComment::CONTEXT_RETURN)
            ->firstOrFail();

        $this->assertSame('The attached quotation is unsigned.', $remark->body);
        $this->assertFalse($remark->isEditable());

        // The submitter is told. The office that pressed it is not.
        $this->assertTrue(
            Notification::query()
                ->where('user_id', $clerk->id)
                ->where('type', NotificationType::Returned)
                ->exists(),
            'The submitter must hear that their document came back.',
        );
        $this->assertFalse(
            Notification::query()
                ->where('user_id', $mtoAdmin->id)
                ->where('type', NotificationType::Returned)
                ->exists(),
        );

        // The originating office sees why, where it goes next, and exactly one
        // way on.
        $page = $this->actingAs($clerk)
            ->get(route('documents.show', $document))
            ->assertOk()
            ->viewData('page')['props']['document'];

        $this->assertSame(['resubmitted'], array_column($page['available_actions'], 'value'));
        $this->assertSame('Treasury', $page['return_notice']['returned_by_office']);
        $this->assertSame('The attached quotation is unsigned.', $page['return_notice']['remarks']);
        $this->assertTrue($page['can']['uploadVersion']);

        // Resubmit, with the corrected file in the same submit.
        $this->actingAs($clerk)
            ->post(route('documents.transitions.store', $document), [
                'action' => 'resubmitted',
                'remarks' => 'The quotation is signed now.',
                // Real bytes that differ from the original: fake()->create()
                // writes none, and an identical re-upload is deduplicated rather
                // than versioned.
                'file' => UploadedFile::fake()->createWithContent('request-signed.pdf', '%PDF-1.4 CORRECTED'),
                'replace_reason' => 'Signed quotation attached.',
                'expected_movement_id' => $document->openMovement->id,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $document->refresh();

        $this->assertSame(DocumentStatus::UnderReview, $document->status);
        $this->assertSame(
            $mto->id,
            $document->openMovement->to_office_id,
            'Resubmit goes back to the office that returned it.',
        );

        $corrected = $document->currentFile()->firstOrFail();
        $this->assertSame(2, $corrected->version, 'The correction is the next version of the SAME document.');
        $this->assertSame('request-signed.pdf', $corrected->original_name);
        $this->assertSame($clerk->id, $corrected->uploaded_by_id);
        $this->assertSame($document->openMovement->id, $corrected->document_movement_id);
        Storage::disk('documents')->assertExists($corrected->path);

        $this->assertTrue(
            Notification::query()
                ->where('user_id', $mtoAdmin->id)
                ->where('type', NotificationType::Resubmitted)
                ->exists(),
            'The office that asked for the correction must hear it has come back.',
        );

        // MTO receives the correction, and the route carries on to HRMO...
        $this->actingAs($mtoAdmin)
            ->post(route('documents.transitions.store', $document), [
                'action' => 'received',
                'expected_movement_id' => $document->openMovement->id,
            ])
            ->assertSessionHasNoErrors();

        $document->refresh();
        $this->assertSame($hrmo->id, $document->openMovement->to_office_id);

        // ...whose receipt, as the last office on the route, completes it.
        $this->actingAs($hrmoAdmin)
            ->post(route('documents.transitions.store', $document), [
                'action' => 'received',
                'expected_movement_id' => $document->openMovement->id,
            ])
            ->assertSessionHasNoErrors();

        $document->refresh();
        $this->assertSame(DocumentStatus::Completed, $document->status);

        // ONE document the whole way: one control number, one QR label, and a
        // trail that runs straight through the return.
        $this->assertSame(1, Document::query()->count());
        $this->assertSame($controlNumber, $document->control_number);
        $this->assertSame($qrToken, $document->qr_token);
        $this->assertSame(
            [
                'registered', 'received', 'forwarded',
                'returned', 'resubmitted',
                'received', 'forwarded', 'received', 'completed',
            ],
            $document->movements()->get()->map(fn ($leg) => $leg->action->value)->all(),
        );
    }

    /**
     * A corrected file is attached to a resubmit and to nothing else, and a
     * resubmit that fails attaches nothing at all.
     */
    public function test_a_corrected_file_only_rides_along_with_a_resubmit(): void
    {
        Storage::fake('documents');

        $mpdo = $this->office('MPDO', 'Planning Office');
        $mto = $this->office('MTO', 'Treasury');
        $clerk = $this->staff($mpdo);
        $mtoAdmin = $this->admin($mto);
        $document = $this->registerDocument($mpdo, $clerk);

        $this->actingAs($this->admin($mpdo))
            ->post(route('documents.transitions.store', $document), [
                'action' => 'forwarded',
                'to_office_id' => $mto->id,
                'expected_movement_id' => $document->openMovement->id,
            ])
            ->assertSessionHasNoErrors();

        // On a receipt the file would be silently dropped, so it is refused.
        $this->actingAs($mtoAdmin)
            ->post(route('documents.transitions.store', $document), [
                'action' => 'received',
                'file' => UploadedFile::fake()->create('stray.pdf', 10, 'application/pdf'),
                'expected_movement_id' => $document->fresh()->openMovement->id,
            ])
            ->assertSessionHasErrors('file');

        $this->actingAs($mtoAdmin)
            ->post(route('documents.transitions.store', $document), [
                'action' => 'returned',
                'remarks' => 'Wrong form.',
                'expected_movement_id' => $document->fresh()->openMovement->id,
            ])
            ->assertSessionHasNoErrors();

        // A stale tab: the resubmit is refused, and no version is written.
        $stale = $document->fresh()->openMovement->id - 1;

        $this->actingAs($clerk)
            ->post(route('documents.transitions.store', $document), [
                'action' => 'resubmitted',
                'file' => UploadedFile::fake()->create('fixed.pdf', 10, 'application/pdf'),
                'expected_movement_id' => $stale,
            ]);

        $this->assertSame(DocumentStatus::Returned, $document->fresh()->status);
        $this->assertSame(0, $document->files()->count());

        // Receiving it back at the originating office is not a way round the
        // resubmit -- it would advance a kept route past the office waiting.
        $this->actingAs($clerk)
            ->post(route('documents.transitions.store', $document), [
                'action' => 'received',
                'expected_movement_id' => $document->fresh()->openMovement->id,
            ])
            ->assertForbidden();

        // And nobody but the originating office holds it to resubmit.
        $this->actingAs($mtoAdmin)
            ->post(route('documents.transitions.store', $document), [
                'action' => 'resubmitted',
                'expected_movement_id' => $document->fresh()->openMovement->id,
            ])
            ->assertForbidden();
    }

    /**
     * Return has nowhere to go while the originating office holds the document
     * itself -- they can upload a corrected version where it sits.
     */
    public function test_return_is_not_offered_at_the_originating_office(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $document = $this->registerDocument($office, $this->staff($office));

        $this->actingAs($admin)
            ->post(route('documents.transitions.store', $document), [
                'action' => 'received',
                'expected_movement_id' => $document->openMovement->id,
            ])
            ->assertRedirect();

        $document->refresh();

        $this->assertNotContains(
            'returned',
            array_column(
                $this->actingAs($admin)
                    ->get(route('documents.show', $document))
                    ->assertOk()
                    ->viewData('page')['props']['document']['available_actions'],
                'value',
            ),
        );

        $this->actingAs($admin)
            ->post(route('documents.transitions.store', $document), [
                'action' => 'returned',
                'remarks' => 'Back to myself.',
                'expected_movement_id' => $document->openMovement->id,
            ])
            ->assertForbidden();

        $this->assertSame(DocumentStatus::UnderReview, $document->fresh()->status);
    }

    /**
     * Returning is a DECISION, so it carries the gate every decision carries:
     * Admin-only, and not on your own document while §A6's switch is off.
     *
     * That gate is what made APPROVAL unusable, and it is harmless here for one
     * reason -- nothing waits on a return. The clerk below cannot return, and
     * can still receive, so the folder keeps moving either way.
     *
     * The document is held away from its originating office, because at the
     * originating office nobody can return it and the test would prove nothing
     * about the clerk.
     */
    public function test_a_clerk_cannot_return_but_can_still_move_the_folder(): void
    {
        $origin = $this->office();
        $office = $this->office('MTO', 'Treasury');
        $clerk = $this->staff($office);
        $document = $this->registerDocument($origin, $this->staff($origin));

        $this->actingAs($this->admin($origin))
            ->post(route('documents.transitions.store', $document), [
                'action' => 'forwarded',
                'to_office_id' => $office->id,
                'expected_movement_id' => $document->openMovement->id,
            ])
            ->assertSessionHasNoErrors();

        $document->refresh();

        $this->actingAs($clerk)
            ->post(route('documents.transitions.store', $document), [
                'action' => 'received',
                'expected_movement_id' => $document->openMovement->id,
            ])
            ->assertRedirect();

        $document->refresh();

        $offered = array_column(
            $this->actingAs($clerk)
                ->get(route('documents.show', $document))
                ->assertOk()
                ->viewData('page')['props']['document']['available_actions'],
            'value',
        );

        $this->assertNotContains('returned', $offered);
        $this->assertContains('received', $offered, 'A clerk must still be able to receive.');

        $this->actingAs($clerk)
            ->post(route('documents.transitions.store', $document), [
                'action' => 'returned',
                'remarks' => 'Trying it anyway.',
                'expected_movement_id' => $document->openMovement->id,
            ])
            ->assertForbidden();

        $this->assertSame(DocumentStatus::UnderReview, $document->fresh()->status);
    }

    /**
     * The removed actions are refused over HTTP, not merely hidden.
     *
     * The buttons are gone from the page because DocumentPresenter builds them
     * from the same map that guards the server -- but a hand-rolled POST, or a
     * tab left open across the deploy, still has to bounce.
     *
     * `rejected` is in this list again: on 2026-09-15 the client asked for the
     * Reject button to become Return. `returned` is not, because it is
     * reachable -- test_a_returned_document_is_corrected_and_carries_on_as_the_same_document
     * covers it from the other side.
     */
    public function test_the_removed_decision_actions_are_refused_over_http(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $document = $this->registerDocument($office, $this->staff($office));

        $this->actingAs($admin)->post(route('documents.transitions.store', $document), [
            'action' => 'received',
            'expected_movement_id' => $document->openMovement->id,
        ]);

        foreach (['approved', 'rejected'] as $action) {
            $document->refresh();

            $this->actingAs($admin)
                ->post(route('documents.transitions.store', $document), [
                    'action' => $action,
                    'remarks' => 'Trying it anyway.',
                    'expected_movement_id' => $document->openMovement->id,
                ])
                ->assertForbidden();

            $this->assertSame(
                DocumentStatus::UnderReview,
                $document->fresh()->status,
                "A refused '{$action}' must leave the document exactly where it was.",
            );
        }
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

    /**
     * Separation of duties, on the one action it still applies to.
     *
     * Approving was the action this rule was written for, and it is gone.
     * Completing a document is what inherited it: it is terminal, it is
     * Admin-only, and signing off on your own paperwork is exactly what
     * client question A6 asked to be able to prevent.
     *
     * Receiving is deliberately NOT covered -- see the test below.
     */
    public function test_an_admin_cannot_close_their_own_submission_by_default(): void
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
                'action' => 'completed',
                'remarks' => 'Closing my own request',
                'expected_movement_id' => $document->openMovement->id,
            ])
            ->assertForbidden();
    }

    /**
     * Receiving your own document is always allowed, switch or no switch.
     *
     * This is the client's bug, stated as a rule. Acknowledging that a folder
     * reached your desk is a receipt, not a judgement on its contents -- there
     * is no duty to separate. When the route waited on approval instead, an
     * office whose own admin had filed the document could not release it, and
     * every stop behind it sat on "Waiting" for good.
     */
    public function test_receiving_your_own_document_is_never_blocked(): void
    {
        config(['cicto.workflow.allow_self_approval' => false]);

        $office = $this->office();
        $admin = $this->admin($office);
        $document = $this->registerDocument($office, $admin);

        $this->actingAs($admin)
            ->post(route('documents.transitions.store', $document), [
                'action' => 'received',
                'expected_movement_id' => $document->openMovement->id,
            ])
            ->assertRedirect();

        $this->assertSame(DocumentStatus::UnderReview, $document->fresh()->status);
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
                'action' => 'completed',
                'remarks' => 'Closed',
                'expected_movement_id' => $document->openMovement->id,
            ])
            ->assertRedirect();

        $this->assertSame(DocumentStatus::Completed, $document->fresh()->status);
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

        // Now a remark attached to a ledger leg. A forward, because the three
        // decision actions were removed on 2026-09-03 -- what is being pinned
        // here is that a remark written into the trail cannot be edited
        // afterwards, whichever action carried it.
        $this->actingAs($admin)->post(route('documents.transitions.store', $document), [
            'action' => 'received',
            'expected_movement_id' => $document->fresh()->openMovement->id,
        ]);
        $this->actingAs($admin)->post(route('documents.transitions.store', $document), [
            'action' => 'forwarded',
            'to_office_id' => $this->office('MTO', 'Treasury')->id,
            'remarks' => 'Please attach the quotation.',
            'expected_movement_id' => $document->fresh()->openMovement->id,
        ]);

        $remark = DocumentComment::query()->where('context', DocumentComment::CONTEXT_MOVEMENT)->firstOrFail();

        $this->actingAs($admin)
            ->patch(route('documents.comments.update', [$document, $remark]), ['body' => 'Rewritten history'])
            ->assertForbidden();
    }
}
