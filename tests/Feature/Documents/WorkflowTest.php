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
     * The client asked for "received lang, wala nang iba". These three are what
     * that removed, and this is the test that keeps them removed.
     *
     * Approving in particular is why the client's documents kept dying at the
     * third department: it was the only action that advanced a route, and
     * DocumentPolicy makes it Admin-only and forbids it to the document's own
     * author, so any queued office without a qualifying approver held the
     * folder forever.
     */
    public function test_no_reachable_stage_offers_approve_reject_or_return(): void
    {
        $removed = [
            MovementAction::Approved,
            MovementAction::Rejected,
            MovementAction::Returned,
        ];

        foreach ([DocumentStatus::Initiated, DocumentStatus::UnderReview] as $status) {
            foreach ($removed as $action) {
                $this->assertFalse(
                    DocumentWorkflow::allows($status, $action),
                    "{$status->value} must not offer {$action->value} any more.",
                );
            }
        }

        // What under_review DOES offer, in full. Asserted as a whole set rather
        // than one membership at a time, so putting an action back is a
        // deliberate edit to this list and not an accident.
        $this->assertEqualsCanonicalizing(
            [MovementAction::Forwarded, MovementAction::Received, MovementAction::Completed],
            DocumentWorkflow::allowed(DocumentStatus::UnderReview),
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
            $this->assertTrue(DocumentWorkflow::canForward($status));
        }

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

    /**
     * WHAT USED TO BE HERE. Two tests pinned Return's destination -- back to
     * the office that sent it, falling back to the originating office on the
     * genesis leg. Returning was removed from the workflow on 2026-09-03 at the
     * client's request, so there is no longer a stage it can be performed from
     * and the tests had nothing left to drive.
     *
     * TransitionDocument still carries the `Returned` arm that works out that
     * destination, and MovementAction still has the case. Both are kept
     * deliberately: legs written before the change still say "returned" and
     * §13's timeline has to render them, and if the client asks for the button
     * back the subtle half of the feature is still there. Put a test back
     * beside it if they do.
     */
}
