<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\TransitionDocument;
use App\Enums\DocumentStatus;
use App\Enums\MovementAction;
use App\Exceptions\IllegalTransitionException;
use App\Exceptions\StaleWorkflowStateException;
use App\Models\DocumentComment;
use App\Models\DocumentMovement;
use App\Support\DocumentWorkflow;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

class WorkflowTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    public function test_forwarding_closes_one_leg_and_opens_exactly_one_other(): void
    {
        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');
        $admin = $this->admin($mpdo);

        $document = $this->registerDocument($mpdo, $this->staff($mpdo));
        $openLeg = $document->openMovement;

        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Forwarded,
            actor: $admin,
            remarks: 'Please review',
            toOfficeId: $mto->id,
            expectedMovementId: $openLeg->id,
        );

        $legs = DocumentMovement::query()->where('document_id', $document->id)->get();

        $this->assertCount(2, $legs);
        $this->assertSame(
            1,
            $legs->whereNull('departed_at')->count(),
            'A document must have exactly one open leg.',
        );

        $closed = $legs->firstWhere('sequence', 1);
        $open = $legs->firstWhere('sequence', 2);

        $this->assertNotNull($closed->departed_at);
        $this->assertNull($closed->is_open);
        $this->assertSame($mpdo->id, $open->from_office_id);
        $this->assertSame($mto->id, $open->to_office_id);
        $this->assertSame(1, $open->is_open);

        $document->refresh();
        $this->assertSame(DocumentStatus::UnderReview, $document->status);
    }

    public function test_the_database_rejects_a_second_open_leg(): void
    {
        $office = $this->office();
        $document = $this->registerDocument($office, $this->staff($office));

        // unique(document_id, is_open) is the real guarantee behind the
        // one-open-leg invariant. Both drivers allow many NULLs in a unique
        // index, which is what makes it portable.
        $this->expectException(QueryException::class);

        DocumentMovement::create([
            'document_id' => $document->id,
            'sequence' => 99,
            'to_office_id' => $office->id,
            'action' => MovementAction::Received,
            'arrived_at' => now(),
            'is_open' => 1,
        ]);
    }

    public function test_a_double_submit_is_rejected_rather_than_applied_twice(): void
    {
        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');
        $admin = $this->admin($mpdo);

        $document = $this->registerDocument($mpdo, $this->staff($mpdo));
        $staleLegId = $document->openMovement->id;

        $transition = app(TransitionDocument::class);

        $transition->handle(
            document: $document,
            action: MovementAction::Forwarded,
            actor: $admin,
            toOfficeId: $mto->id,
            expectedMovementId: $staleLegId,
        );

        // The second request carries the leg id the page was rendered from,
        // which is no longer the open one.
        $this->expectException(StaleWorkflowStateException::class);

        $transition->handle(
            document: $document->fresh(),
            action: MovementAction::Forwarded,
            actor: $admin,
            toOfficeId: $mto->id,
            expectedMovementId: $staleLegId,
        );
    }

    /**
     * The client's flow of 2026-09-03: an office acknowledges the folder and
     * that is the whole of its involvement. No approval step exists to wait on.
     */
    public function test_receiving_is_the_only_step_and_it_keeps_the_folder_movable(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $document = $this->registerDocument($office, $this->staff($office));

        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Received,
            actor: $admin,
            expectedMovementId: $document->openMovement->id,
        );

        $document->refresh();

        // Receiving is not a stage change -- the folder is acknowledged, not
        // advanced -- so it stays under review and stays sendable.
        $this->assertSame(DocumentStatus::UnderReview, $document->status);
        $this->assertTrue(DocumentWorkflow::canForward($document->status));

        // And it can be received again by the next office it reaches.
        $this->assertTrue(
            DocumentWorkflow::allows(DocumentStatus::UnderReview, MovementAction::Received),
        );
    }

    /**
     * The client asked for "received lang" on 2026-09-03, which removed approve,
     * reject and return. Reject came back on 2026-09-13, and on 2026-09-15 the
     * client asked for that button to be Return instead, so a refused document
     * could be corrected and carry on as the same document. Approve and reject
     * stay out, and this is the test that keeps them out.
     *
     * Approving in particular is why the client's documents kept dying at the
     * third department: it was the only action that advanced a route, and
     * DocumentPolicy makes it Admin-only and forbids it to the document's own
     * author, so any queued office without a qualifying approver held the
     * folder forever. Return is safe to offer for the exact reason approve was
     * not -- nothing waits on it, because the route advances on `received`.
     */
    public function test_no_reachable_stage_offers_approve_or_reject(): void
    {
        $removed = [
            MovementAction::Approved,
            MovementAction::Rejected,
        ];

        foreach ([DocumentStatus::Initiated, DocumentStatus::UnderReview, DocumentStatus::Returned] as $status) {
            foreach ($removed as $action) {
                $this->assertFalse(
                    DocumentWorkflow::allows($status, $action),
                    "{$status->value} must not offer {$action->value} any more.",
                );
            }
        }

        // Return is offered where a document can actually be sent back, and
        // only there: `initiated` means nobody has picked the folder up yet, so
        // no office is in a position to ask for a correction.
        $this->assertTrue(
            DocumentWorkflow::allows(DocumentStatus::UnderReview, MovementAction::Returned),
        );
        $this->assertFalse(
            DocumentWorkflow::allows(DocumentStatus::Initiated, MovementAction::Returned),
        );

        // What under_review DOES offer, in full. Asserted as a whole set rather
        // than one membership at a time, so putting an action back is a
        // deliberate edit to this list and not an accident.
        $this->assertEqualsCanonicalizing(
            [
                MovementAction::Forwarded,
                MovementAction::Received,
                MovementAction::Returned,
                MovementAction::Completed,
            ],
            DocumentWorkflow::allowed(DocumentStatus::UnderReview),
        );

        // And a returned document has exactly one way on. A receipt would
        // advance its kept route past the office waiting for the correction,
        // and a hand-picked send would replace that route.
        $this->assertSame(
            [MovementAction::Resubmitted],
            DocumentWorkflow::allowed(DocumentStatus::Returned),
        );
    }

    /**
     * Return sends the document to its ORIGINATING office, and resubmit sends
     * it back to the office that returned it.
     *
     * Deliberately three offices, so "the originating office" and "the office
     * before this one" are different answers: the Phase 2 design returned to
     * the previous office, and the client's request of 2026-09-15 is that the
     * office which filed the document gets it back to correct.
     */
    public function test_return_goes_to_the_originating_office_and_resubmit_comes_back(): void
    {
        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');
        $hrmo = $this->office('HRMO', 'Human Resource');
        $clerk = $this->staff($mpdo);
        $document = $this->registerDocument($mpdo, $clerk);

        foreach ([[$mpdo, $mto], [$mto, $hrmo]] as [$from, $to]) {
            app(TransitionDocument::class)->handle(
                document: $document->refresh(),
                action: MovementAction::Forwarded,
                actor: $this->admin($from),
                toOfficeId: $to->id,
                expectedMovementId: $document->refresh()->openMovement->id,
            );
        }

        $hrmoAdmin = $this->admin($hrmo);

        app(TransitionDocument::class)->handle(
            document: $document->refresh(),
            action: MovementAction::Returned,
            actor: $hrmoAdmin,
            remarks: 'Missing the signed attachment.',
            expectedMovementId: $document->refresh()->openMovement->id,
        );

        $document->refresh();

        $this->assertSame(DocumentStatus::Returned, $document->status);
        $this->assertFalse($document->status->isTerminal(), 'A returned document is waiting, not finished.');
        $this->assertSame($mpdo->id, $document->openMovement->to_office_id, 'Back to the office that filed it, not to MTO.');
        $this->assertSame($hrmo->id, $document->openMovement->from_office_id);

        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Resubmitted,
            actor: $clerk,
            expectedMovementId: $document->openMovement->id,
        );

        $document->refresh();

        $this->assertSame(DocumentStatus::UnderReview, $document->status);
        $this->assertSame($hrmo->id, $document->openMovement->to_office_id, 'Back to the office that returned it.');
        $this->assertSame(MovementAction::Resubmitted, $document->openMovement->action);
    }

    /** Only a document sitting on its returned leg knows where a resubmit goes. */
    public function test_a_resubmit_with_no_return_to_answer_is_refused(): void
    {
        $office = $this->office();
        $document = $this->registerDocument($office, $this->staff($office));

        // A status edited by hand, with no returned leg behind it.
        $document->forceFill(['status' => DocumentStatus::Returned->value])->save();

        $this->expectException(IllegalTransitionException::class);

        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Resubmitted,
            actor: $this->admin($office),
            expectedMovementId: $document->openMovement->id,
        );
    }

    /**
     * The stages nothing can enter any more still have a way out.
     *
     * A document that was sitting in `approved` or `returned` when the receipt
     * flow shipped is a real row in the client's database, and stranding it
     * would mean a folder that exists on somebody's desk and can never be
     * moved, completed or archived again.
     */
    public function test_a_document_left_in_a_retired_stage_can_still_be_moved(): void
    {
        foreach ([DocumentStatus::Approved, DocumentStatus::Returned] as $status) {
            $this->assertNotSame(
                [],
                DocumentWorkflow::allowed($status),
                "A document stuck in {$status->value} must still have a way out.",
            );
        }

        // A returned document's way out is Resubmit, not a hand-picked send --
        // see test_no_reachable_stage_offers_approve_or_reject.
        $this->assertTrue(DocumentWorkflow::canForward(DocumentStatus::Approved));

        $office = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');
        $admin = $this->admin($office);
        $document = $this->registerDocument($office, $this->staff($office));

        // Put it where the old workflow could have left it.
        $document->forceFill(['status' => DocumentStatus::Approved->value])->save();

        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Forwarded,
            actor: $admin,
            toOfficeId: $mto->id,
            expectedMovementId: $document->openMovement->id,
        );

        $document->refresh();
        $this->assertSame(DocumentStatus::UnderReview, $document->status);
        $this->assertSame($mto->id, $document->openMovement->to_office_id);
    }

    public function test_illegal_transitions_throw_and_write_nothing(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $document = $this->registerDocument($office, $this->staff($office));

        $legsBefore = DocumentMovement::query()->count();

        try {
            // A document that is only Initiated cannot be approved.
            app(TransitionDocument::class)->handle(
                document: $document,
                action: MovementAction::Approved,
                actor: $admin,
                remarks: 'Nope',
                expectedMovementId: $document->openMovement->id,
            );
            $this->fail('Expected an IllegalTransitionException.');
        } catch (IllegalTransitionException $e) {
            $this->assertSame(DocumentStatus::Initiated, $e->from);
            $this->assertSame(MovementAction::Approved, $e->action);
        }

        // The exception is thrown inside the transaction, so nothing is left
        // half-written.
        $this->assertSame($legsBefore, DocumentMovement::query()->count());
        $this->assertSame(DocumentStatus::Initiated, $document->fresh()->status);
    }

    public function test_every_terminal_status_offers_no_further_action(): void
    {
        foreach ([DocumentStatus::Completed, DocumentStatus::Rejected] as $status) {
            $this->assertTrue($status->isTerminal());
            $this->assertSame([], DocumentWorkflow::allowed($status));
        }
    }

    public function test_a_decision_remark_is_mirrored_into_comments_with_identical_text(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $document = $this->registerDocument($office, $this->staff($office));

        $transition = app(TransitionDocument::class);
        $transition->handle(
            document: $document,
            action: MovementAction::Received,
            actor: $admin,
            expectedMovementId: $document->openMovement->id,
        );

        $document->refresh();

        /*
         * A forward, because that is what carries a remark now: the three
         * decision actions were removed on 2026-09-03 and the mirror is not
         * about them -- it is about any remark that becomes a ledger entry.
         */
        $transition->handle(
            document: $document,
            action: MovementAction::Forwarded,
            actor: $admin,
            remarks: 'Missing supporting documents.',
            toOfficeId: $this->office('MTO', 'Treasury')->id,
            expectedMovementId: $document->openMovement->id,
        );

        // The remark lives on the leg the action CREATED, alongside the
        // matching immutable copy in movements.remarks.
        $movement = DocumentMovement::query()
            ->where('document_id', $document->id)
            ->where('action', MovementAction::Forwarded)
            ->firstOrFail();

        $comment = DocumentComment::query()
            ->where('document_movement_id', $movement->id)
            ->first();

        $this->assertNotNull($comment);
        $this->assertSame($movement->remarks, $comment->body);
        $this->assertSame(DocumentComment::CONTEXT_MOVEMENT, $comment->context);

        // The ledger copy is immutable, so the two can never diverge. Every
        // context except CONTEXT_COMMENT is locked, which is the rule that
        // keeps the panel and the trail from disagreeing.
        $this->assertFalse($comment->isEditable());
    }

    public function test_completing_a_document_closes_every_leg_and_stamps_completed_at(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $document = $this->registerDocument($office, $this->staff($office));

        $transition = app(TransitionDocument::class);

        foreach ([MovementAction::Received, MovementAction::Completed] as $action) {
            $document->refresh();
            $transition->handle(
                document: $document,
                action: $action,
                actor: $admin,
                expectedMovementId: $document->openMovement?->id,
            );
        }

        $document->refresh();

        $this->assertSame(DocumentStatus::Completed, $document->status);
        $this->assertNotNull($document->completed_at);
        $this->assertNull($document->openMovement, 'A completed document is held by nobody.');
        $this->assertSame(
            0,
            DocumentMovement::query()->where('document_id', $document->id)->whereNull('departed_at')->count(),
        );
    }
}
