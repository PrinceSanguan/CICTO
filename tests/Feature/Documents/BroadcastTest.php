<?php

namespace Tests\Feature\Documents;

use App\Enums\MovementAction;
use App\Enums\NotificationType;
use App\Models\Document;
use App\Models\DocumentComment;
use App\Models\DocumentType;
use App\Models\Notification;
use App\Models\Office;
use App\Models\User;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\OfficeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * "Broadcast to ALL offices" (client, DTS_Office_Routing_Paths.pdf,
 * 2026-09-25) -- step 4 of an Executive Order, step 2 of a Memorandum
 * Circular. Every office is told and may read it; the folder does not move.
 */
class BroadcastTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    private Document $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([OfficeSeeder::class, DocumentTypeSeeder::class]);
        Storage::fake('documents');

        // An Executive Order: the Mayor issues it, Legal and the Council see
        // it, then it goes to the Archive.
        $this->order = $this->file('EXEC-ORDER', ['OCM', 'CLO', 'SP', 'ARO']);
    }

    private function at(string $code): Office
    {
        return Office::query()->where('code', $code)->firstOrFail();
    }

    private function adminAt(string $code): User
    {
        $office = $this->at($code);

        return User::query()->where('office_id', $office->id)->where('role', 'admin')->first()
            ?? $this->admin($office);
    }

    /** @param  list<string>  $codes */
    private function file(string $type, array $codes): Document
    {
        $this->actingAs($this->staff($this->at($codes[0])))->post(route('documents.store'), [
            'title' => 'Executive Order No. 12',
            'document_type_id' => DocumentType::query()->where('code', $type)->value('id'),
            'office_ids' => array_map(fn (string $code) => $this->at($code)->id, $codes),
            'priority' => 'normal',
            'file' => UploadedFile::fake()->create('eo.pdf', 40, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        return Document::query()->latest('id')->firstOrFail();
    }

    private function receive(Document $document): void
    {
        $holder = Office::query()->findOrFail($document->refresh()->openMovement->to_office_id);

        $this->actingAs($this->adminAt($holder->code))->post(route('documents.transitions.store', $document), [
            'action' => MovementAction::Received->value,
            'expected_movement_id' => $document->openMovement->id,
        ])->assertSessionHasNoErrors();
    }

    private function broadcast(User $user, ?Document $document = null): TestResponse
    {
        return $this->actingAs($user)->post(route('documents.broadcast', $document ?? $this->order));
    }

    public function test_the_office_holding_it_sends_it_to_every_office(): void
    {
        // OCM, then Legal: the folder is at the Council now.
        $this->receive($this->order);
        $this->receive($this->order);
        $this->assertSame($this->at('SP')->id, $this->order->refresh()->openMovement->to_office_id);

        $cenro = $this->staff($this->at('CENRO'));
        $bplo = $this->adminAt('BPLO');
        $noOffice = User::factory()->create(['office_id' => null]);
        $sp = $this->adminAt('SP');

        $this->actingAs($sp)->get(route('documents.show', $this->order))
            ->assertInertia(fn (Assert $page) => $page->where('document.can.broadcast', true));

        $this->broadcast($sp)
            ->assertSessionHas('toast', fn (array $toast) => $toast['type'] === 'success'
                && str_contains($toast['message'], 'was sent to every office.'));

        $this->order->refresh();
        $this->assertNotNull($this->order->broadcast_at);
        $this->assertSame($sp->id, $this->order->broadcast_by_id);

        // The folder did not move: it is still the Council's, on its route.
        $this->assertSame($this->at('SP')->id, $this->order->openMovement->to_office_id);
        $this->assertSame(1, $this->order->routeStops()->where('status', 'pending')->count());

        // Everyone in an office is told, once; not the sender, and not an
        // account that belongs to no office.
        $told = fn (User $user) => Notification::query()->where('user_id', $user->id)->where('type', NotificationType::Broadcast->value)->count();
        $this->assertSame(1, $told($cenro));
        $this->assertSame(1, $told($bplo));
        $this->assertSame(0, $told($sp));
        $this->assertSame(0, $told($noOffice));
    }

    public function test_every_office_can_read_it_and_do_nothing_else(): void
    {
        DocumentComment::query()->create([
            'document_id' => $this->order->id,
            'user_id' => $this->adminAt('OCM')->id,
            'body' => 'Legal still has to see paragraph 3.',
            'context' => DocumentComment::CONTEXT_COMMENT,
            'is_internal' => true,
        ]);

        $reader = $this->adminAt('CENRO');
        $this->assertFalse($reader->can('view', $this->order), 'Not before it is broadcast.');

        $this->broadcast($this->adminAt('OCM'))->assertSessionHas('toast');

        // Listed in Track Documents, and opens.
        $this->actingAs($reader)->get(route('documents.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('documents.data.0.id', $this->order->id)
                ->where('documents.data.0.is_broadcast', true));

        $this->actingAs($reader)->get(route('documents.show', $this->order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('document.read_only_broadcast', true)
                ->where('document.broadcast.by', $this->adminAt('OCM')->name)
                ->where('document.available_actions', [])
                ->where('document.can.comment', false)
                ->where('document.can.archive', false)
                ->where('document.can.broadcast', false)
                ->where('comments', []));

        // Its file, yes.
        $file = $this->order->files()->firstOrFail();
        $this->actingAs($reader)->get(route('documents.files.download', [$this->order, $file]))->assertOk();

        // A comment, no.
        $this->actingAs($reader)->post(route('documents.comments.store', $this->order), ['body' => 'Noted.'])
            ->assertForbidden();

        // And it is not CENRO's work: not in its reports.
        $this->assertFalse(Document::query()->visibleTo($reader)->whereKey($this->order->id)->exists());
    }

    public function test_it_is_bell_only_not_email(): void
    {
        config(['mail.default' => 'smtp', 'cicto.notifications.email' => true]);
        Mail::fake();
        $this->withoutDefer();

        $this->broadcast($this->adminAt('OCM'))->assertSessionHas('toast');

        Mail::assertNothingSent();
    }

    public function test_once_only(): void
    {
        $cenro = $this->adminAt('CENRO');

        $this->broadcast($this->adminAt('OCM'))->assertSessionHas('toast');
        $this->broadcast($this->adminAt('OCM'))->assertForbidden();

        $this->assertSame(1, Notification::query()
            ->where('user_id', $cenro->id)
            ->where('type', NotificationType::Broadcast->value)
            ->count());
    }

    public function test_who_may_press_it(): void
    {
        // At OCM, the office that issued it and holds it.
        $this->assertTrue($this->adminAt('OCM')->can('broadcast', $this->order));
        $this->assertFalse($this->staff($this->at('OCM'))->can('broadcast', $this->order), 'An Admin\'s job, like sending it on.');
        $this->assertFalse($this->adminAt('SP')->can('broadcast', $this->order), 'Not an office it has not reached.');
        $this->assertFalse($this->adminAt('CENRO')->can('broadcast', $this->order));
        $this->assertTrue($this->superAdmin()->can('broadcast', $this->order));

        $this->broadcast($this->adminAt('CENRO'))->assertForbidden();

        // Only types that are broadcast.
        $memo = $this->file('MEMO', ['OCM', 'CLO']);
        $this->assertFalse($this->adminAt('OCM')->can('broadcast', $memo));
        $this->broadcast($this->adminAt('OCM'), $memo)->assertForbidden();
        $this->assertNull($memo->refresh()->broadcast_at);
    }

    public function test_the_issuing_office_may_still_send_it_after_it_is_completed(): void
    {
        foreach (range(1, 4) as $hop) {
            $this->receive($this->order);
        }

        $this->assertSame('completed', $this->order->refresh()->status->value);
        $this->assertTrue($this->adminAt('OCM')->can('broadcast', $this->order), 'The office that issued it.');
        $this->assertFalse($this->adminAt('SP')->can('broadcast', $this->order), 'Nobody holds it now.');

        // Sent out after it was finished: every office reads it, and none
        // that it never passed through may file it away.
        $this->broadcast($this->adminAt('OCM'))->assertSessionHas('toast');
        $reader = $this->adminAt('CENRO');
        $this->assertTrue($reader->can('view', $this->order->refresh()));
        $this->assertFalse($reader->can('archive', $this->order));
        $this->assertTrue($this->adminAt('ARO')->can('archive', $this->order), 'The office it finished at still may.');
    }

    public function test_not_while_it_is_out_for_correction(): void
    {
        // OCM receives: the folder goes on to Legal, who sends it back.
        $this->receive($this->order);

        $this->actingAs($this->adminAt('CLO'))->post(route('documents.transitions.store', $this->order), [
            'action' => MovementAction::Returned->value,
            'remarks' => 'Wrong ordinance cited.',
            'expected_movement_id' => $this->order->refresh()->openMovement->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame('returned', $this->order->refresh()->status->value);
        $this->assertFalse($this->adminAt('OCM')->can('broadcast', $this->order));
    }

    public function test_a_memorandum_circular_can_be_broadcast_too(): void
    {
        $circular = $this->file('MEMO-CIRCULAR', ['OCM']);

        $this->broadcast($this->adminAt('OCM'), $circular)->assertSessionHas('toast');
        $this->assertTrue($this->staff($this->at('PESO'))->can('view', $circular->refresh()));
    }
}
