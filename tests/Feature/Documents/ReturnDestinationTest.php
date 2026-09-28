<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\TransitionDocument;
use App\Enums\DocumentStatus;
use App\Enums\MovementAction;
use App\Enums\NotificationType;
use App\Mail\DocumentNotificationMail;
use App\Models\Document;
use App\Models\Notification;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * Where a Return goes (client request, 2026-09-25): "dapat po pwedeng pumili
 * kung saan sya babalik ... naka depende sa mga office na nadaanan na ng
 * document". Any office the document has already been at -- not only the one
 * that filed it, which stays the default.
 */
class ReturnDestinationTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    private Office $mpdo;

    private Office $mto;

    private Office $hrmo;

    private User $clerk;

    private Document $document;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mpdo = $this->office('MPDO', 'Planning Office');
        $this->mto = $this->office('MTO', 'Treasury');
        $this->hrmo = $this->office('HRMO', 'Human Resource');
        $this->clerk = $this->staff($this->mpdo);

        // Planning files it, Treasury passes it on, HR is holding it now.
        $this->document = $this->registerDocument($this->mpdo, $this->clerk);
        $this->forward($this->mpdo, $this->mto);
        $this->forward($this->mto, $this->hrmo);
    }

    private function forward(Office $from, Office $to): void
    {
        app(TransitionDocument::class)->handle(
            document: $this->document->refresh(),
            action: MovementAction::Forwarded,
            actor: $this->adminOf($from),
            toOfficeId: $to->id,
            expectedMovementId: $this->document->refresh()->openMovement->id,
        );
    }

    private function adminOf(Office $office): User
    {
        return User::query()->where('office_id', $office->id)->where('role', 'admin')->where('is_active', true)->first()
            ?? $this->admin($office);
    }

    private function returnTo(User $actor, ?Office $to, string $remarks = 'Unsigned page 2.'): TestResponse
    {
        return $this->actingAs($actor)->post(route('documents.transitions.store', $this->document), array_filter([
            'action' => MovementAction::Returned->value,
            'remarks' => $remarks,
            'return_to_office_id' => $to?->id,
            'expected_movement_id' => $this->document->refresh()->openMovement->id,
        ], fn ($value) => $value !== null));
    }

    public function test_the_choices_are_the_offices_it_has_been_at_originating_first(): void
    {
        $this->actingAs($this->adminOf($this->hrmo))
            ->get(route('documents.show', $this->document))
            ->assertInertia(fn (Assert $page) => $page
                ->has('document.return_options', 2)
                ->where('document.return_options.0.name', 'Planning Office')
                ->where('document.return_options.0.is_originating', true)
                ->where('document.return_options.1.name', 'Treasury')
                ->where('document.return_options.1.is_originating', false));
    }

    public function test_it_can_be_returned_to_an_office_in_the_middle_and_that_office_resubmits(): void
    {
        $hrAdmin = $this->adminOf($this->hrmo);

        $this->returnTo($hrAdmin, $this->mto)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('toast', fn (array $toast) => str_contains($toast['message'], 'returned to Treasury'));

        $this->document->refresh();
        $this->assertSame(DocumentStatus::Returned, $this->document->status);
        $this->assertSame($this->mto->id, $this->document->openMovement->to_office_id);
        $this->assertSame($this->hrmo->id, $this->document->openMovement->from_office_id);

        // The page names where it went and who sent it back.
        $this->actingAs($this->adminOf($this->mto))
            ->get(route('documents.show', $this->document))
            ->assertInertia(fn (Assert $page) => $page
                ->where('document.return_notice.returned_to_office', 'Treasury')
                ->where('document.return_notice.returned_by_office', 'Human Resource'));

        // Treasury holds it, so Treasury -- clerk or Admin -- resubmits; the
        // office that filed it does not hold it and cannot.
        $this->assertTrue($this->staff($this->mto)->can('act', [$this->document, MovementAction::Resubmitted]));
        $this->assertFalse($this->clerk->can('act', [$this->document, MovementAction::Resubmitted]));

        $this->actingAs($this->adminOf($this->mto))->post(route('documents.transitions.store', $this->document), [
            'action' => MovementAction::Resubmitted->value,
            'expected_movement_id' => $this->document->openMovement->id,
        ])->assertSessionHasNoErrors();

        $this->document->refresh();
        $this->assertSame(DocumentStatus::UnderReview, $this->document->status);
        $this->assertSame($this->hrmo->id, $this->document->openMovement->to_office_id, 'Back to the office that returned it.');
    }

    public function test_with_no_choice_it_still_goes_to_the_office_that_filed_it(): void
    {
        $this->returnTo($this->adminOf($this->hrmo), null)->assertSessionHasNoErrors();

        $this->assertSame($this->mpdo->id, $this->document->refresh()->openMovement->to_office_id);
    }

    public function test_an_office_it_has_never_been_at_is_refused(): void
    {
        $elsewhere = $this->office('SP', 'Council');

        $this->returnTo($this->adminOf($this->hrmo), $elsewhere)
            ->assertSessionHasErrors(['return_to_office_id' => 'Choose one of the offices this document has already passed through.']);

        // Nor to itself.
        $this->returnTo($this->adminOf($this->hrmo), $this->hrmo)->assertSessionHasErrors('return_to_office_id');

        $this->assertSame(DocumentStatus::UnderReview, $this->document->refresh()->status);

        // And the only writer of movements refuses it too, request or not.
        $this->expectException(\InvalidArgumentException::class);
        app(TransitionDocument::class)->handle(
            document: $this->document,
            action: MovementAction::Returned,
            actor: $this->adminOf($this->hrmo),
            remarks: 'x',
            toOfficeId: $elsewhere->id,
            expectedMovementId: $this->document->openMovement->id,
        );
    }

    public function test_a_deactivated_office_is_not_offered(): void
    {
        $this->mto->forceFill(['is_active' => false])->save();

        $this->actingAs($this->adminOf($this->hrmo))
            ->get(route('documents.show', $this->document))
            ->assertInertia(fn (Assert $page) => $page
                ->has('document.return_options', 1)
                ->where('document.return_options.0.name', 'Planning Office'));

        $this->returnTo($this->adminOf($this->hrmo), $this->mto)->assertSessionHasErrors('return_to_office_id');
    }

    public function test_an_office_with_nobody_to_resubmit_is_labelled(): void
    {
        User::query()->where('office_id', $this->mto->id)->update(['is_active' => false]);

        $this->actingAs($this->adminOf($this->hrmo))
            ->get(route('documents.show', $this->document))
            ->assertInertia(fn (Assert $page) => $page
                ->where('document.return_options.1.name', 'Treasury')
                ->where('document.return_options.1.has_staff', false)
                ->where('document.return_options.0.has_staff', true));
    }

    public function test_a_document_still_at_the_office_that_filed_it_has_nowhere_to_return_to(): void
    {
        $fresh = $this->registerDocument($this->mpdo, $this->clerk);

        $this->assertTrue($fresh->returnDestinations()->isEmpty());
        $this->assertFalse($this->adminOf($this->mpdo)->can('act', [$fresh, MovementAction::Returned]));
    }

    public function test_the_office_that_filed_it_can_return_it_to_where_it_has_been(): void
    {
        // Sent back to Planning by hand: Planning now holds it and may return
        // it to Treasury or HR -- which a return-to-the-filer rule never allowed.
        $this->forward($this->hrmo, $this->mpdo);
        $planning = $this->adminOf($this->mpdo);

        $this->assertTrue($planning->can('act', [$this->document->refresh(), MovementAction::Returned]));
        $this->assertSame(['Treasury', 'Human Resource'], $this->document->returnDestinations()->pluck('name')->all());

        // Nothing chosen and the filer is holding it: the next office it had
        // been at, never a return to the same desk.
        app(TransitionDocument::class)->handle(
            document: $this->document->refresh(),
            action: MovementAction::Returned,
            actor: $planning,
            remarks: 'Wrong form.',
            expectedMovementId: $this->document->refresh()->openMovement->id,
        );

        $this->assertSame($this->mto->id, $this->document->refresh()->openMovement->to_office_id);
    }

    public function test_the_office_it_goes_back_to_is_told_and_not_the_filer(): void
    {
        config(['mail.default' => 'smtp', 'cicto.notifications.email' => true]);
        Mail::fake();
        $this->withoutDefer();

        $treasury = $this->adminOf($this->mto);
        $planning = $this->adminOf($this->mpdo);

        $this->returnTo($this->adminOf($this->hrmo), $this->mto)->assertSessionHasNoErrors();

        $returned = fn (User $user): bool => Notification::query()
            ->where('user_id', $user->id)
            ->where('type', NotificationType::Returned->value)
            ->exists();

        $this->assertTrue($returned($treasury), 'The office it went back to gets the bell.');
        $this->assertFalse($returned($this->clerk), 'The filer is not asked to fix what Treasury is fixing.');
        $this->assertFalse($returned($planning));

        Mail::assertSent(DocumentNotificationMail::class, fn (DocumentNotificationMail $mail) => $mail->hasTo($treasury->email)
            && $mail->type === NotificationType::Returned);
        Mail::assertNotSent(DocumentNotificationMail::class, fn (DocumentNotificationMail $mail) => $mail->hasTo($this->clerk->email));
    }

    public function test_a_return_on_a_route_comes_back_and_the_route_still_finishes(): void
    {
        $routed = $this->registerDocument($this->mpdo, $this->clerk);
        $post = fn (User $actor, array $data) => $this->actingAs($actor)
            ->post(route('documents.transitions.store', $routed), [
                ...$data,
                'expected_movement_id' => $routed->refresh()->openMovement->id,
            ])
            ->assertSessionHasNoErrors();

        // Planning plans Treasury then HR; Treasury's receipt sends it on.
        $post($this->adminOf($this->mpdo), ['action' => 'forwarded', 'to_office_ids' => [$this->mto->id, $this->hrmo->id]]);
        $post($this->adminOf($this->mto), ['action' => 'received']);
        $this->assertSame($this->hrmo->id, $routed->refresh()->openMovement->to_office_id);

        // HR, the last stop, sends it back to Treasury rather than to Planning.
        $post($this->adminOf($this->hrmo), ['action' => 'returned', 'remarks' => 'Treasury stamp missing.', 'return_to_office_id' => $this->mto->id]);
        $this->assertSame($this->mto->id, $routed->refresh()->openMovement->to_office_id);

        // Treasury corrects and resubmits: back to HR, which can still only
        // receive or return -- and receiving finishes the route.
        $post($this->adminOf($this->mto), ['action' => 'resubmitted']);
        $this->assertSame($this->hrmo->id, $routed->refresh()->openMovement->to_office_id);
        $this->assertTrue($routed->isAtLastRouteStop());

        $post($this->adminOf($this->hrmo), ['action' => 'received']);
        $this->assertSame(DocumentStatus::Completed, $routed->refresh()->status);
    }
}
