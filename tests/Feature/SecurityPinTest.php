<?php

namespace Tests\Feature;

use App\Actions\Documents\RegisterDocument;
use App\Actions\Users\ManageSecurityPin;
use App\Enums\DocumentPriority;
use App\Enums\MovementAction;
use App\Enums\SecurityEventType;
use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Support\SecurityPin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * The Security PIN (client request, 2026-09-25): each person's own 4-digit PIN,
 * asked for before a document is shown and again after the idle timeout.
 *
 * Every test here starts LOCKED (actingAsLocked). The base TestCase signs
 * everybody else in already unlocked, so the rest of the suite tests documents
 * rather than this prompt.
 */
class SecurityPinTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    private const PIN = '2580';

    private function documentWithFile(): Document
    {
        Storage::fake('documents');

        $office = $this->office();

        return app(RegisterDocument::class)->handle(
            title: 'Confidential payroll adjustment',
            documentTypeId: $this->documentType()->id,
            priority: DocumentPriority::Normal,
            originatingOffice: $office,
            creator: $this->staff($office),
            remarks: 'Salary figures inside',
            upload: UploadedFile::fake()->createWithContent('payroll.pdf', '%PDF-1.4 payroll'),
        )->refresh();
    }

    private function withPin(User $user, string $pin = self::PIN): User
    {
        app(ManageSecurityPin::class)->set($user, $pin);

        return $user->refresh();
    }

    public function test_a_locked_session_gets_the_prompt_and_nothing_of_the_document(): void
    {
        $document = $this->documentWithFile();
        $clerk = $document->creator;

        $response = $this->actingAsLocked($clerk)->get(route('documents.show', $document));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('documents/locked')
            ->where('document.id', $document->id)
            ->where('document.control_number', $document->control_number)
            ->where('hasPin', false)
            ->missing('document.title')
            ->missing('timeline')
            ->missing('files')
            ->missing('comments')
            ->missing('signatures'));

        // Not in the page anywhere -- not merely left out of one prop.
        $response->assertDontSee('Confidential payroll adjustment');
        $response->assertDontSee('Salary figures inside');
    }

    public function test_somebody_who_may_not_see_the_document_is_refused_not_prompted(): void
    {
        $document = $this->documentWithFile();
        $outsider = $this->staff($this->office('MTO', 'Treasury'));

        $this->actingAsLocked($outsider)
            ->get(route('documents.show', $document))
            ->assertForbidden();
    }

    public function test_creating_a_pin_stores_only_a_hash_and_opens_the_document(): void
    {
        $document = $this->documentWithFile();
        $clerk = $document->creator;

        $this->actingAsLocked($clerk)
            ->from(route('documents.show', $document))
            ->post(route('security-pin.store'), ['pin' => self::PIN, 'pin_confirmation' => self::PIN])
            ->assertRedirect(route('documents.show', $document))
            ->assertSessionHasNoErrors();

        $clerk->refresh();

        $this->assertNotSame(self::PIN, $clerk->security_pin);
        $this->assertTrue(Hash::check(self::PIN, (string) $clerk->security_pin));
        $this->assertNotNull($clerk->security_pin_set_at);
        $this->assertTrue(SecurityEvent::query()->where('type', SecurityEventType::SecurityPinCreated->value)->exists());

        $this->get(route('documents.show', $document))
            ->assertInertia(fn (Assert $page) => $page
                ->component('documents/show')
                ->where('document.title', 'Confidential payroll adjustment'));
    }

    public function test_a_new_pin_must_be_four_confirmed_digits_that_are_not_easy_to_guess(): void
    {
        $clerk = $this->staff($this->office());

        $refused = [
            ['123', '123'],        // too short
            ['12345', '12345'],    // too long
            ['12a4', '12a4'],      // not digits
            ['2580', '2581'],      // confirmation differs
            ['1111', '1111'],      // one digit four times
            ['1234', '1234'],      // a run up
            ['8765', '8765'],      // a run down
            ['0123', '0123'],      // a run from zero
        ];

        foreach ($refused as [$pin, $confirmation]) {
            $this->actingAsLocked($clerk)
                ->post(route('security-pin.store'), ['pin' => $pin, 'pin_confirmation' => $confirmation])
                ->assertSessionHasErrors('pin');
        }

        $this->assertFalse($clerk->refresh()->hasSecurityPin());
    }

    public function test_a_person_with_a_pin_cannot_simply_create_another(): void
    {
        $clerk = $this->withPin($this->staff($this->office()));

        // Somebody at an unlocked desk choosing a new PIN for its owner.
        $this->actingAsLocked($clerk)
            ->post(route('security-pin.store'), ['pin' => '4826', 'pin_confirmation' => '4826'])
            ->assertSessionHasErrors('pin');

        $this->assertTrue(Hash::check(self::PIN, (string) $clerk->refresh()->security_pin));
    }

    public function test_the_right_pin_opens_documents(): void
    {
        $document = $this->documentWithFile();
        $clerk = $this->withPin($document->creator);

        $this->actingAsLocked($clerk)
            ->get(route('documents.show', $document))
            ->assertInertia(fn (Assert $page) => $page->component('documents/locked')->where('hasPin', true));

        $this->post(route('security-pin.verify'), ['pin' => self::PIN])->assertSessionHasNoErrors();

        $this->get(route('documents.show', $document))
            ->assertInertia(fn (Assert $page) => $page->component('documents/show'));
    }

    public function test_a_wrong_pin_says_how_many_tries_are_left(): void
    {
        $document = $this->documentWithFile();
        $clerk = $this->withPin($document->creator);

        $this->actingAsLocked($clerk)
            ->post(route('security-pin.verify'), ['pin' => '9147'])
            ->assertSessionHasErrors(['pin' => 'That PIN is not correct. 4 tries left before you are signed out.']);

        $this->get(route('documents.show', $document))
            ->assertInertia(fn (Assert $page) => $page
                ->component('documents/locked')
                ->where('attemptsLeft', 4));
    }

    public function test_five_wrong_pins_sign_the_session_out(): void
    {
        $clerk = $this->withPin($this->staff($this->office()));

        $this->actingAsLocked($clerk);

        foreach (range(1, 4) as $attempt) {
            $this->post(route('security-pin.verify'), ['pin' => '9147'])->assertSessionHasErrors('pin');
            $this->assertAuthenticatedAs($clerk);
        }

        $this->post(route('security-pin.verify'), ['pin' => '9147'])
            ->assertRedirect(route('login'))
            ->assertSessionHas('status');

        $this->assertGuest();
        $this->assertTrue(SecurityEvent::query()->where('type', SecurityEventType::SecurityPinLockout->value)->exists());
    }

    /**
     * Found in review 2026-09-25: the count lived in the session, so a burst
     * of simultaneous guesses all read the same stale count. Attempts are now
     * numbered BEFORE they are checked; one numbered past the limit is refused
     * without being compared -- even when it is the right PIN.
     */
    public function test_an_attempt_past_the_limit_is_not_checked_even_if_right(): void
    {
        $clerk = $this->withPin($this->staff($this->office()));

        // Five guesses already counted, e.g. sent all at once from a script.
        foreach (range(1, 5) as $attempt) {
            SecurityPin::countAttempt($clerk);
        }

        $this->actingAsLocked($clerk)
            ->post(route('security-pin.verify'), ['pin' => self::PIN])
            ->assertRedirect(route('login'));

        $this->assertGuest();

        // And the owner, signing back in with their password, has every try.
        $this->assertSame(SecurityPin::maxAttempts(), SecurityPin::attemptsLeft($clerk));
    }

    /**
     * Found in review 2026-09-25: a bare `throttle:N,1` is keyed on the user
     * alone, so the page's heartbeat used to spend the same allowance as
     * entering the PIN -- a busy page could get a right PIN refused with 429.
     */
    public function test_the_heartbeat_does_not_use_up_the_pin_entry_allowance(): void
    {
        $document = $this->documentWithFile();
        $clerk = $this->withPin($document->creator);

        $this->actingAsLocked($clerk);

        foreach (range(1, 12) as $beat) {
            $this->postJson(route('security-pin.heartbeat'))->assertStatus(423);
        }

        $this->post(route('security-pin.verify'), ['pin' => self::PIN])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->get(route('documents.show', $document))
            ->assertInertia(fn (Assert $page) => $page->component('documents/show'));
    }

    public function test_the_right_pin_starts_the_count_again(): void
    {
        $clerk = $this->withPin($this->staff($this->office()));

        $this->actingAsLocked($clerk);

        foreach (range(1, 4) as $attempt) {
            $this->post(route('security-pin.verify'), ['pin' => '9147']);
        }

        $this->post(route('security-pin.verify'), ['pin' => self::PIN])->assertSessionHasNoErrors();

        // Four more wrong after a right one is still not five in a row.
        foreach (range(1, 4) as $attempt) {
            $this->post(route('security-pin.verify'), ['pin' => '9147']);
        }

        $this->assertAuthenticatedAs($clerk);
    }

    public function test_the_unlock_lapses_after_the_idle_timeout_but_not_while_in_use(): void
    {
        config(['cicto.security_pin.idle_minutes' => 5]);

        $document = $this->documentWithFile();
        $clerk = $this->withPin($document->creator);

        Carbon::setTestNow('2026-09-25 09:00:00');
        $this->actingAsLocked($clerk)->post(route('security-pin.verify'), ['pin' => self::PIN]);

        // Opening documents every four minutes keeps it open well past five.
        foreach (['09:04:00', '09:08:00', '09:12:00'] as $time) {
            Carbon::setTestNow("2026-09-25 {$time}");
            $this->get(route('documents.show', $document))
                ->assertInertia(fn (Assert $page) => $page->component('documents/show'));
        }

        // Five minutes of nothing after that, and it asks again.
        Carbon::setTestNow('2026-09-25 09:17:00');
        $this->get(route('documents.show', $document))
            ->assertInertia(fn (Assert $page) => $page->component('documents/locked'));
    }

    public function test_the_heartbeat_keeps_a_reader_unlocked_and_reports_a_lapse(): void
    {
        config(['cicto.security_pin.idle_minutes' => 5]);

        $document = $this->documentWithFile();
        $clerk = $this->withPin($document->creator);

        Carbon::setTestNow('2026-09-25 09:00:00');
        $this->actingAsLocked($clerk)->post(route('security-pin.verify'), ['pin' => self::PIN]);

        // Reading without opening anything new: only the page's heartbeat
        // tells the server somebody is still there.
        Carbon::setTestNow('2026-09-25 09:04:00');
        $this->postJson(route('security-pin.heartbeat'))->assertNoContent();

        Carbon::setTestNow('2026-09-25 09:08:00');
        $this->get(route('documents.show', $document))
            ->assertInertia(fn (Assert $page) => $page->component('documents/show'));

        Carbon::setTestNow('2026-09-25 09:14:00');
        $this->postJson(route('security-pin.heartbeat'))->assertStatus(423);

        // And a lapsed unlock is not revived by the heartbeat that found it.
        $this->get(route('documents.show', $document))
            ->assertInertia(fn (Assert $page) => $page->component('documents/locked'));
    }

    public function test_locking_closes_documents_and_clears_the_browser_history(): void
    {
        $document = $this->documentWithFile();
        $clerk = $this->withPin($document->creator);

        $this->actingAsLocked($clerk)->post(route('security-pin.verify'), ['pin' => self::PIN]);

        $this->from(route('documents.show', $document))
            ->post(route('security-pin.lock'), ['reason' => 'idle'])
            ->assertRedirect(route('documents.show', $document));

        $page = $this->get(route('documents.show', $document))->viewData('page');

        $this->assertSame('documents/locked', $page['component']);
        $this->assertSame('idle', $page['props']['lockedBecause']);

        // Back after a lock must ask the server, not redraw the document.
        $this->assertTrue($page['clearHistory']);
    }

    public function test_an_unlocked_document_is_kept_in_history_encrypted_and_never_cached(): void
    {
        $document = $this->documentWithFile();

        $response = $this->actingAs($document->creator)->get(route('documents.show', $document));

        $this->assertTrue($response->viewData('page')['encryptHistory']);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_files_and_actions_on_a_document_need_the_pin_too(): void
    {
        $document = $this->documentWithFile();
        $file = $document->currentFile()->firstOrFail();
        $admin = $this->withPin($this->admin($document->originatingOffice));
        $show = route('documents.show', $document);

        $this->actingAsLocked($admin);

        $this->get(route('documents.files.preview', ['document' => $document, 'file' => $file]))
            ->assertRedirect($show);
        $this->get(route('documents.files.download', ['document' => $document, 'file' => $file]))
            ->assertRedirect($show);

        // The signing panel fetches its PDF in the background.
        $this->getJson(route('documents.files.signable', ['document' => $document, 'file' => $file]))
            ->assertStatus(423);

        $before = DocumentMovement::query()->count();

        $this->post(route('documents.transitions.store', $document), [
            'action' => MovementAction::Received->value,
            'expected_movement_id' => $document->openMovement?->id,
        ])->assertRedirect($show);

        $this->post(route('documents.comments.store', $document), ['body' => 'Read it'])
            ->assertRedirect($show);

        $this->assertSame($before, DocumentMovement::query()->count());
        $this->assertSame(0, $document->comments()->count());
    }

    /**
     * Found in deep QA 2026-09-25: a button pressed after the PIN lapsed was
     * bounced to the prompt silently, so a person could enter their PIN and
     * believe their "Received" had gone through.
     */
    public function test_an_action_bounced_by_the_lock_says_nothing_was_saved(): void
    {
        $document = $this->documentWithFile();
        $admin = $this->withPin($this->admin($document->originatingOffice));

        $this->actingAsLocked($admin)
            ->post(route('documents.transitions.store', $document), [
                'action' => MovementAction::Received->value,
                'expected_movement_id' => $document->openMovement?->id,
            ])
            ->assertRedirect(route('documents.show', $document));

        $this->get(route('documents.show', $document))
            ->assertInertia(fn (Assert $page) => $page
                ->component('documents/locked')
                ->where('lockedBecause', 'action'));

        // Merely opening a link while locked is not "nothing was saved".
        $this->get(route('documents.files.preview', ['document' => $document, 'file' => $document->currentFile()->firstOrFail()]));
        $this->get(route('documents.show', $document))
            ->assertInertia(fn (Assert $page) => $page->where('lockedBecause', null));
    }

    public function test_a_refused_pin_is_explained_in_plain_words(): void
    {
        $clerk = $this->withPin($this->staff($this->office()));

        $this->actingAsLocked($clerk)
            ->post(route('security-pin.verify'), ['pin' => '12'])
            ->assertSessionHasErrors(['pin' => 'Your PIN must be exactly 4 digits (0–9).']);

        $this->put(route('security-pin.update'), [
            'current_password' => 'password',
            'pin' => '4826',
            'pin_confirmation' => '4862',
        ])->assertSessionHasErrors(['pin' => 'The two PINs do not match. Type the same 4 digits in both.']);

        $this->put(route('security-pin.update'), [
            'current_password' => 'wrong',
            'pin' => '4826',
            'pin_confirmation' => '4826',
        ])->assertSessionHasErrors(['current_password' => 'That is not your password.']);
    }

    public function test_changing_the_pin_needs_the_account_password(): void
    {
        $clerk = $this->withPin($this->staff($this->office()));

        $this->actingAs($clerk)
            ->put(route('security-pin.update'), [
                'current_password' => 'not-my-password',
                'pin' => '4826',
                'pin_confirmation' => '4826',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check(self::PIN, (string) $clerk->refresh()->security_pin));

        $this->put(route('security-pin.update'), [
            'current_password' => 'password',
            'pin' => '4826',
            'pin_confirmation' => '4826',
        ])->assertSessionHasNoErrors();

        $clerk->refresh();
        $this->assertTrue(Hash::check('4826', (string) $clerk->security_pin));
        $this->assertFalse(Hash::check(self::PIN, (string) $clerk->security_pin));
        $this->assertTrue(SecurityEvent::query()->where('type', SecurityEventType::SecurityPinChanged->value)->exists());
    }

    public function test_a_forgotten_pin_is_replaced_with_the_password_from_the_prompt(): void
    {
        $document = $this->documentWithFile();
        $clerk = $this->withPin($document->creator);

        $this->actingAsLocked($clerk)
            ->from(route('documents.show', $document))
            ->put(route('security-pin.update'), [
                'current_password' => 'password',
                'pin' => '4826',
                'pin_confirmation' => '4826',
            ])
            ->assertRedirect(route('documents.show', $document));

        $this->get(route('documents.show', $document))
            ->assertInertia(fn (Assert $page) => $page->component('documents/show'));
    }

    public function test_a_super_admin_can_clear_somebodys_pin_and_nobody_else_can(): void
    {
        $document = $this->documentWithFile();
        $clerk = $this->withPin($document->creator);
        $admin = $this->admin($document->originatingOffice);

        $this->actingAs($admin)
            ->delete(route('super-admin.users.security-pin', $clerk))
            ->assertForbidden();
        $this->assertTrue($clerk->refresh()->hasSecurityPin());

        $this->actingAs($this->superAdmin())
            ->delete(route('super-admin.users.security-pin', $clerk))
            ->assertRedirect();

        $this->assertFalse($clerk->refresh()->hasSecurityPin());
        $this->assertNull($clerk->security_pin_set_at);
        $this->assertTrue(SecurityEvent::query()->where('type', SecurityEventType::SecurityPinReset->value)->exists());

        // Next time they open a document, they choose a new one.
        $this->actingAsLocked($clerk)
            ->get(route('documents.show', $document))
            ->assertInertia(fn (Assert $page) => $page->component('documents/locked')->where('hasPin', false));
    }

    public function test_the_super_admin_user_list_says_who_has_a_pin(): void
    {
        $withPin = $this->withPin($this->staff($this->office()));

        $this->actingAs($this->superAdmin())
            ->get(route('super-admin.users.index', ['q' => $withPin->email]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('users.data.0.email', $withPin->email)
                ->where('users.data.0.has_security_pin', true));
    }

    public function test_the_console_can_clear_a_pin(): void
    {
        $clerk = $this->withPin($this->staff($this->office()));

        $this->artisan('cicto:user', ['email' => $clerk->email, '--reset-pin' => true])->assertSuccessful();

        $this->assertFalse($clerk->refresh()->hasSecurityPin());
        $this->assertTrue(SecurityEvent::query()->where('type', SecurityEventType::SecurityPinReset->value)->exists());
    }

    public function test_the_pin_itself_never_reaches_the_browser(): void
    {
        $clerk = $this->withPin($this->staff($this->office()));

        $this->actingAs($clerk)
            ->get(route('documents.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->missing('auth.user.security_pin')
                ->where('auth.securityPin.has_pin', true)
                ->where('auth.securityPin.unlocked', true)
                ->where('auth.securityPin.idle_seconds', SecurityPin::idleSeconds()));
    }

    public function test_one_persons_unlock_does_not_open_documents_for_another(): void
    {
        $document = $this->documentWithFile();
        $first = $this->withPin($this->admin($document->originatingOffice));
        $second = $this->withPin($document->creator);

        // A session unlocked by the first person, now signed in as the second.
        $this->actingAsLocked($second)->withSession([SecurityPin::SESSION_KEY => [
            'user_id' => $first->id,
            'at' => now()->getTimestamp(),
        ]]);

        $this->get(route('documents.show', $document))
            ->assertInertia(fn (Assert $page) => $page->component('documents/locked'));
    }

    public function test_switched_off_the_pin_is_never_asked_for(): void
    {
        config(['cicto.security_pin.enabled' => false]);

        $document = $this->documentWithFile();

        $this->actingAsLocked($document->creator)
            ->get(route('documents.show', $document))
            ->assertInertia(fn (Assert $page) => $page
                ->component('documents/show')
                ->where('auth.securityPin', null));
    }

    public function test_the_list_of_documents_does_not_ask_for_the_pin(): void
    {
        $document = $this->documentWithFile();

        // "The pin will pop up only when viewing documents."
        $this->actingAsLocked($document->creator)
            ->get(route('documents.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('documents/index'));
    }
}
