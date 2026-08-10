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

    public function test_approving_makes_send_to_another_office_available(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $document = $this->registerDocument($office, $this->staff($office));

        $transition = app(TransitionDocument::class);

        // §9: the button appears only after approval.
        $this->assertFalse(
            DocumentWorkflow::allows(DocumentStatus::Initiated, MovementAction::Approved),
        );

        $transition->handle(
            document: $document,
            action: MovementAction::Received,
            actor: $admin,
            expectedMovementId: $document->openMovement->id,
        );

        $document->refresh();
        $this->assertSame(DocumentStatus::UnderReview, $document->status);

        $transition->handle(
            document: $document,
            action: MovementAction::Approved,
            actor: $admin,
            remarks: 'Approved',
            expectedMovementId: $document->fresh()->openMovement->id,
        );

        $document->refresh();
        $this->assertSame(DocumentStatus::Approved, $document->status);
        $this->assertTrue(DocumentWorkflow::canForward($document->status));
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

        $transition->handle(
            document: $document,
            action: MovementAction::Rejected,
            actor: $admin,
            remarks: 'Missing supporting documents.',
            expectedMovementId: $document->openMovement->id,
        );

        // The remark lives on the leg the decision CREATED, alongside the
        // matching immutable copy in movements.remarks.
        $movement = DocumentMovement::query()
            ->where('document_id', $document->id)
            ->where('action', MovementAction::Rejected)
            ->firstOrFail();

        $comment = DocumentComment::query()
            ->where('document_movement_id', $movement->id)
            ->first();

        $this->assertNotNull($comment);
        $this->assertSame($movement->remarks, $comment->body);
        $this->assertSame(DocumentComment::CONTEXT_REJECTION, $comment->context);

        // The ledger copy is immutable, so the two can never diverge.
        $this->assertFalse($comment->isEditable());
    }

    public function test_completing_a_document_closes_every_leg_and_stamps_completed_at(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $document = $this->registerDocument($office, $this->staff($office));

        $transition = app(TransitionDocument::class);

        foreach ([MovementAction::Received, MovementAction::Approved, MovementAction::Completed] as $action) {
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

    public function test_returning_a_document_sends_it_back_to_the_office_it_came_from(): void
    {
        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');

        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        // MPDO forwards to MTO.
        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Forwarded,
            actor: $this->admin($mpdo),
            toOfficeId: $mto->id,
            expectedMovementId: $document->openMovement->id,
        );

        $document->refresh();
        $this->assertSame($mto->id, $document->openMovement->to_office_id);

        // MTO returns it for correction. §9 means send it BACK -- leaving it on
        // the reviewer's own desk would make Return indistinguishable from
        // Reject, and only MPDO can make the fix.
        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Returned,
            actor: $this->admin($mto),
            remarks: 'Attach the quotation.',
            expectedMovementId: $document->openMovement->id,
        );

        $document->refresh();

        $this->assertSame(DocumentStatus::Returned, $document->status);
        $this->assertSame(
            $mpdo->id,
            $document->openMovement->to_office_id,
            'A returned document must go back to the office that sent it.',
        );
        $this->assertSame($mto->id, $document->openMovement->from_office_id);
    }

    public function test_returning_on_the_genesis_leg_falls_back_to_the_originating_office(): void
    {
        $office = $this->office('MPDO');
        $admin = $this->admin($office);
        $document = $this->registerDocument($office, $this->staff($office));

        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Received,
            actor: $admin,
            expectedMovementId: $document->openMovement->id,
        );

        $document->refresh();

        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Returned,
            actor: $admin,
            remarks: 'Incomplete.',
            expectedMovementId: $document->openMovement->id,
        );

        $document->refresh();

        // Nowhere else to send it -- there is no previous office.
        $this->assertSame($office->id, $document->openMovement->to_office_id);
    }
}
