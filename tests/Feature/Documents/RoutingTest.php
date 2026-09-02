<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentStatus;
use App\Enums\MovementAction;
use App\Enums\RouteStopStatus;
use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\DocumentRouteStop;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * §9 "Send to Another Office", for several offices in one submit.
 *
 * The client asked on 2026-08-17 to pick multiple offices at once "instead of
 * sending the document one office at a time". It ships as a ROUTING LIST: one
 * submit, N offices, and the folder still travels them one at a time because a
 * physical folder cannot be in two offices at once and the ledger enforces one
 * open leg per document.
 *
 * The invariant these tests really exist to protect is that last part. Every
 * one of them ends up asserting, directly or through the trail, that a five
 * office route is indistinguishable from five hand-typed forwards.
 */
class RoutingTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    /**
     * §5's Department field, when the submitter names more than one.
     *
     * The same request, one step earlier: pick every department at the counter
     * instead of forwarding by hand at each hop. It resolves to the SAME
     * routing list -- registered under the first department, travelling the
     * rest in order -- so submitting to three departments is not a second way
     * of moving a folder, and the ledger cannot tell the two apart.
     */
    public function test_submitting_to_several_departments_registers_under_the_first_and_queues_the_rest(): void
    {
        Storage::fake('documents');

        [$mpdo, $mto, $hrmo] = $this->offices();
        $clerk = $this->staff($mpdo);

        $this->actingAs($clerk)
            ->post(route('documents.store'), [
                'title' => 'Request for office supplies',
                'document_type_id' => $this->documentType()->id,
                'office_ids' => [$mpdo->id, $mto->id, $hrmo->id],
                'priority' => 'normal',
                'file' => UploadedFile::fake()->create('request.pdf', 40, 'application/pdf'),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $document = Document::query()->firstOrFail();

        // The FIRST department registers it: its prefix is burned into the
        // control number, and it is where the folder physically starts.
        $this->assertSame($mpdo->id, $document->originating_office_id);
        $this->assertStringStartsWith('MPDO-', $document->control_number);
        $this->assertSame($mpdo->id, $document->openMovement->to_office_id);

        // The rest are a plan, in the order they were picked -- and no office
        // but the originating one has custody of anything yet.
        $this->assertSame(
            [$mto->id, $hrmo->id],
            $document->routeStops()->pluck('office_id')->all(),
            'The queue must keep the order the submitter picked.',
        );
        $this->assertTrue($document->routeStops()->get()->every(
            fn (DocumentRouteStop $stop) => $stop->status === RouteStopStatus::Pending,
        ));
        $this->assertSame(1, DocumentMovement::query()->where('document_id', $document->id)->count());

        // And it walks the plan on approval, exactly as a route built after
        // registration does.
        $admin = $this->admin($mpdo);
        $this->act($admin, $document, MovementAction::Received);
        $this->act($admin, $document->refresh(), MovementAction::Approved);

        $document->refresh();
        $this->assertSame($mto->id, $document->openMovement->to_office_id);
        $this->assertSame(
            [RouteStopStatus::Visited, RouteStopStatus::Pending],
            $document->routeStops()->get()->pluck('status')->all(),
        );
    }

    public function test_one_submit_sends_to_the_first_office_and_queues_the_rest(): void
    {
        [$mpdo, $mto, $hrmo, $mayor] = $this->offices();
        $admin = $this->admin($mpdo);
        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        $this->send($admin, $document, [$mto, $hrmo, $mayor]);

        $document->refresh();

        // It went to the FIRST office, and only the first.
        $this->assertSame($mto->id, $document->openMovement->to_office_id);
        $this->assertSame(DocumentStatus::UnderReview, $document->status);

        // The rest are a plan, not custody.
        $queue = $document->routeStops()->get();
        $this->assertCount(2, $queue);
        $this->assertSame(
            [$hrmo->id, $mayor->id],
            $queue->pluck('office_id')->all(),
            'The queue must keep the order the sender picked.',
        );
        $this->assertTrue($queue->every(
            fn (DocumentRouteStop $stop) => $stop->status === RouteStopStatus::Pending,
        ));

        $this->assertOneOpenLeg($document);
    }

    public function test_approving_moves_the_document_to_the_next_office_by_itself(): void
    {
        [$mpdo, $mto, $hrmo] = $this->offices();
        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        $this->send($this->admin($mpdo), $document, [$mto, $hrmo]);

        // The receiving office approves. That, and nothing else, releases it.
        $this->act($this->admin($mto), $document, MovementAction::Approved);

        $document->refresh();

        $this->assertSame(
            $hrmo->id,
            $document->openMovement->to_office_id,
            'Approving at stop 1 must send the folder on to stop 2.',
        );
        $this->assertSame(RouteStopStatus::Visited, $document->routeStops()->first()->status);
        $this->assertOneOpenLeg($document);
    }

    public function test_a_routed_document_leaves_the_same_trail_as_forwarding_by_hand(): void
    {
        [$mpdo, $mto, $hrmo] = $this->offices();
        $routed = $this->registerDocument($mpdo, $this->staff($mpdo));
        $byHand = $this->registerDocument($mpdo, $this->staff($mpdo));

        // One submit naming both offices...
        $this->send($this->admin($mpdo), $routed, [$mto, $hrmo]);
        $this->act($this->admin($mto), $routed, MovementAction::Approved);

        // ...against the same journey typed out one office at a time.
        $this->send($this->admin($mpdo), $byHand, [$mto]);
        $this->act($this->admin($mto), $byHand, MovementAction::Approved);
        $this->send($this->admin($mto), $byHand, [$hrmo]);

        $shape = fn (Document $document) => DocumentMovement::query()
            ->where('document_id', $document->id)
            ->orderBy('sequence')
            ->get()
            ->map(fn (DocumentMovement $leg) => [
                $leg->sequence,
                $leg->action->value,
                $leg->from_office_id,
                $leg->to_office_id,
                $leg->is_open,
            ])
            ->all();

        $this->assertSame(
            $shape($byHand),
            $shape($routed),
            'A routing list must be indistinguishable from the same forwards typed by hand.',
        );
    }

    public function test_rejecting_cancels_the_rest_of_the_route(): void
    {
        [$mpdo, $mto, $hrmo] = $this->offices();
        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        $this->send($this->admin($mpdo), $document, [$mto, $hrmo]);
        $this->act($this->admin($mto), $document, MovementAction::Rejected, 'Wrong form attached.');

        $document->refresh();

        $this->assertSame(DocumentStatus::Rejected, $document->status);
        $this->assertSame(
            RouteStopStatus::Cancelled,
            $document->routeStops()->first()->status,
            'A rejected document must not still be listed as travelling.',
        );
        $this->assertSame(
            0,
            $document->routeStops()->where('status', RouteStopStatus::Pending)->count(),
        );
    }

    public function test_completing_cancels_the_rest_of_the_route(): void
    {
        [$mpdo, $mto, $hrmo] = $this->offices();
        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        $this->send($this->admin($mpdo), $document, [$mto, $hrmo]);

        // Approving at MTO auto-advances to HRMO; HRMO completes it there.
        $this->act($this->admin($mto), $document, MovementAction::Approved);
        $this->act($this->admin($hrmo), $document, MovementAction::Approved);
        $this->act($this->admin($hrmo), $document, MovementAction::Completed);

        $document->refresh();

        $this->assertSame(DocumentStatus::Completed, $document->status);
        $this->assertNull($document->openMovement, 'A completed document is held by nobody.');
        $this->assertSame(
            0,
            $document->routeStops()->where('status', RouteStopStatus::Pending)->count(),
        );
    }

    public function test_a_queued_office_cannot_see_the_document_before_it_arrives(): void
    {
        [$mpdo, $mto, $hrmo] = $this->offices();
        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        $this->send($this->admin($mpdo), $document, [$mto, $hrmo]);

        // Being ON the route is a plan, not access. Nothing in the ledger names
        // HRMO yet, and visibleTo() reads the ledger.
        $this->actingAs($this->admin($hrmo))
            ->get(route('documents.show', $document))
            ->assertForbidden();

        // Once it arrives, they can.
        $this->act($this->admin($mto), $document, MovementAction::Approved);

        $this->actingAs($this->admin($hrmo))
            ->get(route('documents.show', $document))
            ->assertOk();
    }

    public function test_re_routing_replaces_the_queue_rather_than_appending_to_it(): void
    {
        [$mpdo, $mto, $hrmo, $mayor] = $this->offices();
        $document = $this->registerDocument($mpdo, $this->staff($mpdo));
        $admin = $this->admin($mpdo);

        $this->send($admin, $document, [$mto, $hrmo]);
        $this->send($this->admin($mto), $document->refresh(), [$mayor]);

        $document->refresh();

        $this->assertSame(
            0,
            $document->routeStops()->where('status', RouteStopStatus::Pending)->count(),
            'Changing your mind must not leave the old tail queued.',
        );
        $this->assertSame($mayor->id, $document->openMovement->to_office_id);
    }

    public function test_the_same_office_cannot_appear_twice_in_one_route(): void
    {
        [$mpdo, $mto] = $this->offices();
        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        $this->actingAs($this->admin($mpdo))
            ->post(route('documents.transitions.store', $document), [
                'action' => 'forwarded',
                'to_office_ids' => [$mto->id, $mto->id],
                'expected_movement_id' => $document->openMovement->id,
            ])
            ->assertSessionHasErrors('to_office_ids');

        $this->assertSame(0, DocumentRouteStop::query()->count());
    }

    public function test_the_single_office_field_still_works(): void
    {
        // Every existing caller -- and any tab left open across the deploy --
        // posts the scalar. It must keep behaving exactly as it did.
        [$mpdo, $mto] = $this->offices();
        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        $this->actingAs($this->admin($mpdo))
            ->post(route('documents.transitions.store', $document), [
                'action' => 'forwarded',
                'to_office_id' => $mto->id,
                'expected_movement_id' => $document->openMovement->id,
            ])
            ->assertSessionHasNoErrors();

        $document->refresh();

        $this->assertSame($mto->id, $document->openMovement->to_office_id);
        $this->assertSame(0, DocumentRouteStop::query()->count(), 'One office is not a route.');
    }

    /**
     * The exact payload the document page posts, for every button on it.
     *
     * This is the test that was missing. The page keeps `to_office_ids` in form
     * state for every action, so Approve, Reject, Return and Complete all
     * posted `to_office_ids[]=` -- one empty element. The rules refused it with
     * "to_office_ids.0 must be an integer", on a field the picker does not even
     * render for those actions, so the button did nothing and said nothing. The
     * other tests missed it because their helper omitted the field entirely,
     * which no browser does.
     *
     * @param  array<string, mixed>  $extra
     */
    #[DataProvider('formActions')]
    public function test_the_payload_the_page_actually_posts_is_accepted(
        string $action,
        array $extra,
    ): void {
        [$mpdo, $mto] = $this->offices();
        $document = $this->registerDocument($mpdo, $this->staff($mpdo));
        $admin = $this->admin($mpdo);

        // Get it under review at MPDO so every decision below is legal.
        $this->act($admin, $document, MovementAction::Received);

        $this->actingAs($admin)
            ->post(route('documents.transitions.store', $document), [
                'action' => $action,
                // Exactly what Inertia sends for an empty array in form state.
                'to_office_ids' => [''],
                'expected_movement_id' => $this->openLegId($document),
                ...$extra,
            ])
            ->assertSessionHasNoErrors();
    }

    /** @return array<string, array{string, array<string, mixed>}> */
    public static function formActions(): array
    {
        return [
            'approve' => ['approved', []],
            'reject' => ['rejected', ['remarks' => 'Missing attachment.']],
            'return' => ['returned', ['remarks' => 'Please correct the total.']],
        ];
    }

    public function test_forwarding_with_no_office_chosen_still_says_so(): void
    {
        [$mpdo] = $this->offices();
        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        $this->actingAs($this->admin($mpdo))
            ->post(route('documents.transitions.store', $document), [
                'action' => 'forwarded',
                'to_office_ids' => [''],
                'expected_movement_id' => $document->openMovement->id,
            ])
            ->assertSessionHasErrors('to_office_ids');
    }

    /**
     * The whole point, stated once: however long the route, the folder is only
     * ever in one place.
     */
    public function test_a_five_office_route_never_opens_a_second_leg(): void
    {
        $mpdo = $this->office('MPDO');
        $destinations = collect(['MTO', 'HRMO', 'MAYOR', 'LEGAL', 'BUDGET'])
            ->map(fn (string $code) => $this->office($code, $code.' Office'));

        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        $this->send($this->admin($mpdo), $document, $destinations->all());
        $this->assertOneOpenLeg($document->refresh());

        foreach ($destinations->take(4) as $office) {
            $this->act($this->admin($office), $document->refresh(), MovementAction::Approved);
            $this->assertOneOpenLeg($document->refresh());
        }

        $this->assertSame(
            $destinations->last()->id,
            $document->refresh()->openMovement->to_office_id,
            'Four approvals should walk the folder to the fifth office.',
        );
    }

    // ---------------------------------------------------------------- helpers

    /** @return list<Office> */
    private function offices(): array
    {
        return [
            $this->office('MPDO'),
            $this->office('MTO', 'Treasury'),
            $this->office('HRMO', 'Human Resource'),
            $this->office('MAYOR', "Mayor's Office"),
        ];
    }

    /** @param  list<Office>  $destinations */
    private function send(User $actor, Document $document, array $destinations): void
    {
        $this->actingAs($actor)
            ->post(route('documents.transitions.store', $document), [
                'action' => 'forwarded',
                'to_office_ids' => array_map(fn (Office $office) => $office->id, $destinations),
                'expected_movement_id' => $this->openLegId($document),
            ])
            ->assertSessionHasNoErrors();
    }

    private function act(
        User $actor,
        Document $document,
        MovementAction $action,
        ?string $remarks = null,
    ): void {
        $this->actingAs($actor)
            ->post(route('documents.transitions.store', $document), array_filter([
                'action' => $action->value,
                'remarks' => $remarks,
                'expected_movement_id' => $this->openLegId($document),
            ]))
            ->assertSessionHasNoErrors();
    }

    /**
     * The open leg as it is RIGHT NOW, not as this PHP instance last saw it.
     *
     * The test model holds a cached relation from before the previous request,
     * and posting that id is exactly the stale-tab case the guard exists to
     * refuse -- which it duly did.
     */
    private function openLegId(Document $document): ?int
    {
        return DocumentMovement::query()
            ->where('document_id', $document->id)
            ->whereNull('departed_at')
            ->value('id');
    }

    private function assertOneOpenLeg(Document $document): void
    {
        $this->assertSame(
            1,
            DocumentMovement::query()
                ->where('document_id', $document->id)
                ->whereNull('departed_at')
                ->count(),
            'A document must have exactly one open leg, however many offices are on its route.',
        );
    }
}
