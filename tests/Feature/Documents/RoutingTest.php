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
     * §5's OTHER shape: several departments, no hierarchy.
     *
     * The flat counterpart to the routing list. Nothing is queued behind
     * anything, so this cannot be one document -- the ledger allows one open
     * leg and `documents.status` is one column. It is one document per
     * department instead, each an ordinary registration, linked only so the
     * page can name the batch.
     */
    public function test_submitting_to_several_departments_at_once_gives_each_its_own_document(): void
    {
        Storage::fake('documents');

        [$mpdo, $mto, $hrmo] = $this->offices();
        $clerk = $this->staff($mpdo);

        $this->actingAs($clerk)
            ->post(route('documents.store'), [
                'title' => 'Memorandum for all departments',
                'document_type_id' => $this->documentType()->id,
                'office_ids' => [$mpdo->id, $mto->id, $hrmo->id],
                'distribution' => 'all_at_once',
                'priority' => 'normal',
                'file' => UploadedFile::fake()->create('memo.pdf', 40, 'application/pdf'),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $documents = Document::query()->orderBy('id')->get();

        $this->assertCount(3, $documents, 'One department, one document.');

        // Each is registered under its OWN department: its own prefix, its own
        // sequence, its own genesis leg. None of them is anybody's second stop.
        $this->assertSame(
            [$mpdo->id, $mto->id, $hrmo->id],
            $documents->pluck('originating_office_id')->all(),
        );

        foreach ($documents as $document) {
            $this->assertSame(
                $document->originating_office_id,
                $document->openMovement->to_office_id,
                'Every copy starts at its own department, immediately.',
            );
            $this->assertSame(0, $document->routeStops()->count(), 'Nothing is queued in a flat submit.');
            $this->assertNotNull($document->currentFile()->first(), 'Every department gets the attachment.');
        }

        // Three control numbers, three prefixes, no sharing.
        $this->assertCount(3, $documents->pluck('control_number')->unique());
        $this->assertStringStartsWith('MPDO-', $documents[0]->control_number);
        $this->assertStringStartsWith('MTO-', $documents[1]->control_number);
        $this->assertStringStartsWith('HRMO-', $documents[2]->control_number);

        // One submit, so they are linked -- and linked to nothing else.
        $group = $documents[0]->submission_group_id;
        $this->assertNotNull($group);
        $this->assertSame([$group, $group, $group], $documents->pluck('submission_group_id')->all());
    }

    /**
     * The flat submit's whole point: no department can hold up another. One
     * finishing with its copy leaves the other two exactly where they were.
     *
     * Asserted by closing one copy outright rather than by rejecting it: both
     * are terminal, and completing is the one that cannot be waved away as
     * "well, it failed anyway".
     */
    public function test_one_department_finishing_does_not_touch_the_others(): void
    {
        Storage::fake('documents');

        [$mpdo, $mto, $hrmo] = $this->offices();

        $this->actingAs($this->staff($mpdo))
            ->post(route('documents.store'), [
                'title' => 'Memorandum for all departments',
                'document_type_id' => $this->documentType()->id,
                'office_ids' => [$mpdo->id, $mto->id, $hrmo->id],
                'distribution' => 'all_at_once',
                'priority' => 'normal',
                'file' => UploadedFile::fake()->create('memo.pdf', 40, 'application/pdf'),
            ])->assertSessionHasNoErrors();

        $mine = Document::query()->where('originating_office_id', $mto->id)->firstOrFail();
        $admin = $this->admin($mto);

        $this->act($admin, $mine, MovementAction::Received);
        $this->act($admin, $mine->refresh(), MovementAction::Completed, 'Handled.');

        $this->assertSame(DocumentStatus::Completed, $mine->refresh()->status);

        foreach ([$mpdo, $hrmo] as $untouched) {
            $other = Document::query()->where('originating_office_id', $untouched->id)->firstOrFail();

            $this->assertSame(
                DocumentStatus::Initiated,
                $other->status,
                'One department finishing must not reach into another.',
            );
        }
    }

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

        // And it walks the plan on RECEIPT, exactly as a route built after
        // registration does. One acknowledgement, no approval.
        $this->act($this->admin($mpdo), $document, MovementAction::Received);

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

        /*
         * The WHOLE list the sender picked is written down, in their order --
         * the office it went to now marked visited, the rest still queued.
         *
         * The first office used to be left out entirely, on the reasoning that
         * it is a movement rather than a plan. But the Route panel renders
         * these rows, so a three-office send drew a two-office route and the
         * office the folder had just gone to was missing from it. Same class of
         * bug as the originating office (2026-09-13).
         */
        $queue = $document->routeStops()->get();
        $this->assertCount(3, $queue);
        $this->assertSame(
            [$mto->id, $hrmo->id, $mayor->id],
            $queue->pluck('office_id')->all(),
            'The queue must keep the order the sender picked.',
        );
        $this->assertSame(
            [RouteStopStatus::Visited, RouteStopStatus::Pending, RouteStopStatus::Pending],
            $queue->pluck('status')->all(),
            'Only the office the folder actually went to is resolved.',
        );

        $this->assertOneOpenLeg($document);
    }

    /**
     * The client's bug, in one test: the folder must not stop at a queued
     * office waiting for an approval that office may not be able to give.
     */
    public function test_receiving_moves_the_document_to_the_next_office_by_itself(): void
    {
        [$mpdo, $mto, $hrmo] = $this->offices();
        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        $this->send($this->admin($mpdo), $document, [$mto, $hrmo]);

        // The receiving office acknowledges it. That, and nothing else,
        // releases it.
        $this->act($this->admin($mto), $document, MovementAction::Received);

        $document->refresh();

        $this->assertSame(
            $hrmo->id,
            $document->openMovement->to_office_id,
            'Receiving at stop 1 must send the folder on to stop 2.',
        );
        $this->assertSame(RouteStopStatus::Visited, $document->routeStops()->first()->status);
        $this->assertOneOpenLeg($document);
    }

    /**
     * The Actions panel a queued office actually sees.
     *
     * The client asked for no APPROVAL step while a document is travelling. On
     * 2026-09-13 it got a reject button back, and on 2026-09-15 that became a
     * Return button. This is that button set, asserted through the same
     * `available_actions` payload the page renders from -- so it fails if
     * approve or reject creeps back into the workflow map, and it fails if
     * Completed starts being offered halfway down a route.
     */
    public function test_a_queued_office_is_offered_receive_send_and_return(): void
    {
        [$mpdo, $mto, $hrmo] = $this->offices();
        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        $this->send($this->admin($mpdo), $document, [$mto, $hrmo]);

        $actions = $this->actingAs($this->admin($mto))
            ->get(route('documents.show', $document))
            ->assertOk()
            ->viewData('page')['props']['document']['available_actions'];

        $this->assertEqualsCanonicalizing(
            ['forwarded', 'received', 'returned'],
            array_column($actions, 'value'),
            'A stop with an office still queued behind it may receive, send on, or return -- and nothing else.',
        );

        // Exactly one of them nags for a reason, and it is the return.
        // Acknowledging a folder is not a decision to justify.
        $this->assertSame(
            ['returned' => true],
            array_filter(array_column($actions, 'requires_remarks', 'value')),
        );

        // At the LAST stop the queue is empty, so closing the document by hand
        // becomes available beside the receipt that would close it anyway.
        $this->act($this->admin($mto), $document, MovementAction::Received);

        $actions = $this->actingAs($this->admin($hrmo))
            ->get(route('documents.show', $document->refresh()))
            ->assertOk()
            ->viewData('page')['props']['document']['available_actions'];

        $this->assertEqualsCanonicalizing(
            ['forwarded', 'received', 'returned', 'completed'],
            array_column($actions, 'value'),
        );
    }

    /**
     * The client's report of 2026-09-13: "hindi po nakikita dito yung
     * originating office" -- the Route panel, one office short.
     *
     * The §5 form picks the departments as ONE ordered list and registers the
     * document under the first of them, so only picks 2..N ever became
     * document_route_stops rows. A five-department submit therefore drew a
     * four-department route, missing the department it started at.
     *
     * The origin is carried as its own payload key rather than faked into
     * `route`: it has no stop row, nothing queues it, and AdvanceRoute must not
     * find a stop for an office the folder has already left.
     */
    public function test_the_route_payload_names_the_originating_office(): void
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
            ->assertSessionHasNoErrors();

        $document = Document::query()->firstOrFail();

        $props = $this->actingAs($clerk)
            ->get(route('documents.show', $document))
            ->assertOk()
            ->viewData('page')['props']['document'];

        $this->assertSame($mpdo->name, $props['route_origin']['office']);
        $this->assertSame('Origin', $props['route_origin']['status_label']);

        // Origin plus the two stops is the three departments that were picked.
        $this->assertSame(
            [$mpdo->name, $mto->name, $hrmo->name],
            [$props['route_origin']['office'], ...array_column($props['route'], 'office')],
            'The panel must name every department the submitter picked, in order.',
        );

        // And it is still there once the route has been walked, so a finished
        // document does not lose the department it came from.
        $this->act($this->admin($mpdo), $document, MovementAction::Received);
        $this->act($this->admin($mto), $document->refresh(), MovementAction::Received);
        $this->act($this->admin($hrmo), $document->refresh(), MovementAction::Received);

        $props = $this->actingAs($clerk)
            ->get(route('documents.show', $document->refresh()))
            ->assertOk()
            ->viewData('page')['props']['document'];

        $this->assertSame(DocumentStatus::Completed, $document->refresh()->status);
        $this->assertSame($mpdo->name, $props['route_origin']['office']);
    }

    /**
     * The same gap on the other route-building path.
     *
     * "Send to Another Office" with several offices picked forwards to the
     * first and queued the rest, so the panel drew the folder's destination as
     * if it were not part of the plan at all.
     */
    public function test_a_mid_life_route_names_every_office_that_was_picked(): void
    {
        [$mpdo, $mto, $hrmo, $mayor] = $this->offices();
        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        $this->send($this->admin($mpdo), $document, [$mto, $hrmo, $mayor]);

        $props = $this->actingAs($this->admin($mto))
            ->get(route('documents.show', $document->refresh()))
            ->assertOk()
            ->viewData('page')['props']['document'];

        $this->assertSame(
            [$mpdo->name, $mto->name, $hrmo->name, $mayor->name],
            [$props['route_origin']['office'], ...array_column($props['route'], 'office')],
        );

        // The one it went to reads as visited; the two behind it as waiting.
        $this->assertSame(
            ['Visited', 'Waiting', 'Waiting'],
            array_column($props['route'], 'status_label'),
        );
    }

    /**
     * Changing your mind mid-route must not corrupt the plan.
     *
     * The second send cancels what was queued and appends its own offices at
     * higher positions -- positions are monotonic per document and never
     * reused, so the unique(document_id, position) index holds. What the panel
     * then reads back is the whole journey: where it started, where it went,
     * the leg that was dropped, and where it is going now.
     */
    public function test_re_routing_appends_to_the_plan_rather_than_corrupting_it(): void
    {
        [$mpdo, $mto, $hrmo, $mayor] = $this->offices();
        $legal = $this->office('LEGAL', 'Legal Office');

        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        // First plan: MTO now, HRMO queued behind it.
        $this->send($this->admin($mpdo), $document, [$mto, $hrmo]);

        // MTO changes its mind and sends it to the Mayor, then Legal, instead.
        $this->send($this->admin($mto), $document->refresh(), [$mayor, $legal]);

        $document->refresh();

        $props = $this->actingAs($this->admin($mayor))
            ->get(route('documents.show', $document))
            ->assertOk()
            ->viewData('page')['props']['document'];

        $this->assertSame($mpdo->name, $props['route_origin']['office']);
        $this->assertSame(
            [$mto->name, $hrmo->name, $mayor->name, $legal->name],
            array_column($props['route'], 'office'),
            'Stops must read back in position order, the dropped one included.',
        );
        $this->assertSame(
            ['Visited', 'Cancelled', 'Visited', 'Waiting'],
            array_column($props['route'], 'status_label'),
        );

        // Positions are still unique and still ascending.
        $positions = $document->routeStops()->pluck('position')->all();
        $this->assertSame($positions, array_values(array_unique($positions)));
        $this->assertSame($positions, collect($positions)->sort()->values()->all());

        // And the plan still drives the folder: Legal is next, not HRMO.
        $this->act($this->admin($mayor), $document, MovementAction::Received);
        $this->assertSame($legal->id, $document->refresh()->openMovement->to_office_id);
    }

    /**
     * A document nobody routed grows no Route panel, and the origin key must
     * not be what puts one there.
     *
     * show.tsx renders the panel on `route.length > 0`. Sending to exactly one
     * office is an ordinary forward, not a plan -- and a stop row for it would
     * also make AdvanceRoute complete the document the moment that office
     * acknowledged it.
     */
    public function test_a_single_office_forward_still_draws_no_route(): void
    {
        [$mpdo, $mto] = $this->offices();
        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        $this->send($this->admin($mpdo), $document, [$mto]);

        $props = $this->actingAs($this->admin($mto))
            ->get(route('documents.show', $document->refresh()))
            ->assertOk()
            ->viewData('page')['props']['document'];

        $this->assertSame([], $props['route']);
        $this->assertSame(0, DocumentRouteStop::query()->count(), 'One office is not a route.');

        // Receiving it must leave the document open, not close it.
        $this->act($this->admin($mto), $document->refresh(), MovementAction::Received);
        $this->assertSame(DocumentStatus::UnderReview, $document->refresh()->status);
    }

    /**
     * The end of the line closes itself.
     *
     * The client asked for the document to be complete once the last office on
     * the list has received it, rather than sitting open waiting for somebody
     * to notice there is nowhere left to send it.
     */
    public function test_the_last_office_receiving_completes_the_document(): void
    {
        [$mpdo, $mto, $hrmo] = $this->offices();
        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        $this->send($this->admin($mpdo), $document, [$mto, $hrmo]);

        // MTO is stop 1: still somewhere to go, so it travels on.
        $this->act($this->admin($mto), $document, MovementAction::Received);
        $this->assertSame(DocumentStatus::UnderReview, $document->refresh()->status);

        // HRMO is the last stop. Nothing left to forward to.
        $this->act($this->admin($hrmo), $document->refresh(), MovementAction::Received);

        $document->refresh();

        $this->assertSame(DocumentStatus::Completed, $document->status);
        $this->assertNotNull($document->completed_at);
        $this->assertNull($document->openMovement, 'A completed document is held by nobody.');
        $this->assertSame(
            0,
            $document->routeStops()->where('status', RouteStopStatus::Pending)->count(),
        );
    }

    /**
     * A document nobody routed is NOT completed by acknowledging it.
     *
     * The auto-close above keys off the route running out. A document with no
     * route never had one, so its own originating office pressing Received
     * would otherwise close it before any work had been done.
     */
    public function test_receiving_an_unrouted_document_leaves_it_open(): void
    {
        $office = $this->office('MPDO');
        $document = $this->registerDocument($office, $this->staff($office));

        $this->act($this->admin($office), $document, MovementAction::Received);

        $document->refresh();

        $this->assertSame(DocumentStatus::UnderReview, $document->status);
        $this->assertNotNull($document->openMovement, 'It is still on somebody\'s desk.');
        $this->assertSame($office->id, $document->openMovement->to_office_id);
    }

    public function test_a_routed_document_leaves_the_same_trail_as_forwarding_by_hand(): void
    {
        [$mpdo, $mto, $hrmo] = $this->offices();
        $routed = $this->registerDocument($mpdo, $this->staff($mpdo));
        $byHand = $this->registerDocument($mpdo, $this->staff($mpdo));

        // One submit naming both offices...
        $this->send($this->admin($mpdo), $routed, [$mto, $hrmo]);
        $this->act($this->admin($mto), $routed, MovementAction::Received);

        // ...against the same journey typed out one office at a time.
        $this->send($this->admin($mpdo), $byHand, [$mto]);
        $this->act($this->admin($mto), $byHand, MovementAction::Received);
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

    /**
     * Sending the folder somewhere off the plan tears the plan down.
     *
     * A hand-picked destination is the more important case of the two, and the
     * one this covers: a queue that survived the override would send the folder
     * somewhere nobody asked for two hops later. (A RETURN deliberately does
     * not tear it down -- EndToEndTest covers that side.)
     */
    public function test_sending_the_folder_off_the_plan_cancels_the_rest_of_the_route(): void
    {
        [$mpdo, $mto, $hrmo, $mayor] = $this->offices();
        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        $this->send($this->admin($mpdo), $document, [$mto, $hrmo]);

        // MTO holds it, and sends it to the Mayor instead of letting the plan
        // carry it to HRMO.
        $this->send($this->admin($mto), $document->refresh(), [$mayor]);

        $document->refresh();

        $this->assertSame($mayor->id, $document->openMovement->to_office_id);
        $this->assertSame(
            0,
            $document->routeStops()
                ->where('status', RouteStopStatus::Pending)
                ->where('office_id', $hrmo->id)
                ->count(),
            'The overridden tail must not still be listed as travelling.',
        );
    }

    public function test_completing_cancels_the_rest_of_the_route(): void
    {
        [$mpdo, $mto, $hrmo] = $this->offices();
        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        $this->send($this->admin($mpdo), $document, [$mto, $hrmo]);

        // Receiving at MTO auto-advances to HRMO, and receiving at HRMO --
        // the last stop -- closes the document where it stands.
        $this->act($this->admin($mto), $document, MovementAction::Received);
        $this->act($this->admin($hrmo), $document->refresh(), MovementAction::Received);

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
        $this->act($this->admin($mto), $document, MovementAction::Received);

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

    /**
     * Every button the Actions panel can render for a document that is under
     * review with nothing queued behind it.
     *
     * It used to list approve / reject / return, which stopped meaning anything
     * on 2026-09-03: all three became unauthorised, and an unauthorised POST is
     * a 403, which carries no session errors -- so `assertSessionHasNoErrors`
     * passed without the payload ever reaching the rules. These are the actions
     * that are genuinely reachable, so the assertion is load-bearing again.
     *
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function formActions(): array
    {
        return [
            'receive' => ['received', []],
            'complete' => ['completed', ['remarks' => 'Handled.']],
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
            $this->act($this->admin($office), $document->refresh(), MovementAction::Received);
            $this->assertOneOpenLeg($document->refresh());
        }

        $this->assertSame(
            $destinations->last()->id,
            $document->refresh()->openMovement->to_office_id,
            'Four receipts should walk the folder to the fifth office.',
        );
    }

    /**
     * THE DEAD END, named before the folder falls into it.
     *
     * §5's department list and §9's send-to list are every ACTIVE office,
     * staffed or not, so a document can always be sent somewhere nobody works.
     * It arrives, becomes the open leg, and cannot be received: only an Admin
     * may press Received (client, 2026-09-15), and nobody there is one.
     *
     * This is the client's "hindi na-rereceive sa pangatlong office" -- the
     * office number is a coincidence, it is just the first stop on their route
     * with no Admin account. Removing the approval gate on 2026-09-03 cured a
     * different gate with the same symptom, which is why it read as a
     * regression.
     *
     * The behaviour is deliberate and stays; what this pins is that the payload
     * SAYS SO, so the picker can warn before the send instead of after.
     */
    public function test_an_office_with_no_admin_is_flagged_as_unable_to_receive(): void
    {
        $staffed = $this->office('MTO', 'Treasury');
        $this->admin($staffed);

        // A real department with nobody in it -- the client's pilot database is
        // mostly these, and the testing guide has to warn testers by hand.
        $empty = $this->office('HRMO', 'Human Resource');

        // A clerk is not enough: filing is not receiving.
        $clerkOnly = $this->office('MPDO', 'Planning');
        $this->staff($clerkOnly);

        $flags = Office::query()->active()->withReceiver()->get()
            ->mapWithKeys(fn (Office $office) => [$office->code => (bool) $office->can_receive]);

        $this->assertTrue($flags['MTO']);
        $this->assertFalse($flags['HRMO'], 'An office with nobody in it cannot receive.');
        $this->assertFalse($flags['MPDO'], 'A clerk can file, but cannot take a folder in.');
    }

    /** The Submit form has to be told, or it cannot warn. */
    public function test_the_submit_form_is_told_which_departments_cannot_receive(): void
    {
        $staffed = $this->office('MTO', 'Treasury');
        $this->admin($staffed);
        $this->office('HRMO', 'Human Resource');

        $clerk = $this->staff($staffed);

        $offices = $this->actingAs($clerk)
            ->get(route('documents.create'))
            ->assertOk()
            ->viewData('page')['props']['offices'];

        $flags = collect($offices)->mapWithKeys(
            fn (array $office) => [$office['code'] => $office['can_receive']],
        );

        $this->assertTrue($flags['MTO']);
        $this->assertFalse($flags['HRMO']);

        /*
         * And nothing more than the picker needs. withExists() selects
         * `offices.*` unless columns are chosen first, and a column list passed
         * to get() afterwards is quietly discarded -- so this fails the day
         * somebody reorders those two calls and starts shipping every office
         * column, timestamps and all, into the page payload.
         */
        $this->assertEqualsCanonicalizing(
            ['id', 'code', 'name', 'can_receive'],
            array_keys($offices[0]),
        );
    }

    /**
     * And the consequence itself, stated as a test rather than as prose in a
     * seeder docblock: the folder reaches the unstaffed office and stops dead,
     * with the stop behind it still waiting.
     */
    public function test_a_route_stalls_at_the_first_office_with_nobody_to_receive(): void
    {
        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');
        $empty = $this->office('HRMO', 'Human Resource');
        $last = $this->office('MAYOR', "Mayor's Office");
        $this->admin($last);

        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        // MTO -> HRMO (nobody) -> MAYOR.
        $this->send($this->admin($mpdo), $document, [$mto, $empty, $last]);
        $this->act($this->admin($mto), $document, MovementAction::Received);

        $document->refresh();
        $this->assertSame(
            $empty->id,
            $document->openMovement->to_office_id,
            'The folder does arrive -- forwarding never checks whether anyone is there.',
        );

        // Nobody at the empty office can act, because nobody is there at all.
        $this->assertSame(
            0,
            User::query()->where('office_id', $empty->id)->count(),
        );

        // And the stop behind it is still waiting, which is what the client saw.
        $this->assertSame(
            RouteStopStatus::Pending,
            DocumentRouteStop::query()
                ->where('document_id', $document->id)
                ->where('office_id', $last->id)
                ->value('status'),
        );
    }

    /**
     * THE CLIENT'S BUG, end to end: "hindi pa rin na-rereceive yung document
     * pag pangatlong office na tatanggap."
     *
     * Three departments picked on the §5 Submit form. In the client's own
     * database this read as "the third office" because the two offices ahead
     * of it were the two with practice Admin accounts.
     *
     * Received here by each office's Admin, because receiving is the Admin's
     * since 2026-09-15 ("dapat po sa admin lang yon") -- and the clerk who filed
     * it is refused at the first receipt, which is that decision.
     *
     * Walked through the HTTP layer rather than the actions, because the 403
     * came from the policy and the policy is only reached through a request.
     */
    public function test_three_departments_are_received_in_turn_by_their_admins(): void
    {
        Storage::fake('documents');

        [$first, $second, $third] = $this->offices();

        $clerk = $this->staff($first);

        $admins = [
            $first->id => $this->admin($first),
            $second->id => $this->admin($second),
            $third->id => $this->admin($third),
        ];

        $this->actingAs($clerk)
            ->post(route('documents.store'), [
                'title' => 'Three department route',
                'document_type_id' => $this->documentType()->id,
                'office_ids' => [$first->id, $second->id, $third->id],
                'distribution' => 'in_order',
                'priority' => 'normal',
                'file' => UploadedFile::fake()->create('memo.pdf', 40, 'application/pdf'),
            ])
            ->assertSessionHasNoErrors();

        $document = Document::query()->latest('id')->firstOrFail();

        // The clerk filed it; the clerk does not take it in.
        $this->actingAs($clerk)
            ->post(route('documents.transitions.store', $document), [
                'action' => MovementAction::Received->value,
                'expected_movement_id' => $this->openLegId($document),
            ])
            ->assertForbidden();

        $this->assertSame($first->id, $document->refresh()->openMovement->to_office_id);

        // The originating office acknowledges, and the folder leaves for stop 1.
        $this->act($admins[$first->id], $document, MovementAction::Received);
        $this->assertSame($second->id, $document->refresh()->openMovement->to_office_id);

        // Stop 1 acknowledges, and the folder leaves for stop 2 -- the hop that
        // never used to happen.
        $this->act($admins[$second->id], $document->refresh(), MovementAction::Received);
        $this->assertSame(
            $third->id,
            $document->refresh()->openMovement->to_office_id,
            'The THIRD office must actually receive the folder.',
        );

        // The third office is the end of the line, so its receipt closes it.
        $this->act($admins[$third->id], $document->refresh(), MovementAction::Received);

        $document->refresh();

        $this->assertSame(DocumentStatus::Completed, $document->status);
        $this->assertNull($document->openMovement);
        $this->assertSame(
            0,
            $document->routeStops()->where('status', RouteStopStatus::Pending)->count(),
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
