<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\TransitionDocument;
use App\Enums\MovementAction;
use App\Enums\NotificationType;
use App\Mail\DocumentNotificationMail;
use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * §12 by email (2026-09-24): an office is emailed when a document is sent to it.
 *
 * phpunit.xml pins MAIL_MAILER=array, which OutgoingMail counts as no transport
 * at all -- so every test that expects a message sets `smtp` first, or it would
 * pass by sending nothing. Mail::fake() then captures what would have left.
 */
class NotificationEmailTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.default' => 'smtp', 'cicto.notifications.email' => true]);
        Mail::fake();

        // Sends are deferred until after the response. Run them on the spot,
        // except in the one test that is about that deferral.
        $this->withoutDefer();
    }

    private function forward(Document $document, User $actor, Office $to, ?string $remarks = null): DocumentMovement
    {
        return app(TransitionDocument::class)->handle(
            document: $document->refresh(),
            action: MovementAction::Forwarded,
            actor: $actor,
            toOfficeId: $to->id,
            remarks: $remarks,
            expectedMovementId: $document->refresh()->openMovement->id,
        );
    }

    public function test_forwarding_emails_everyone_at_the_receiving_office_but_not_the_sender(): void
    {
        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');

        $sender = $this->admin($mpdo);
        $receivingAdmin = $this->admin($mto);
        $receivingClerk = $this->staff($mto);
        $bystander = $this->staff($this->office('HRMO', 'HR'));

        $document = $this->registerDocument($mpdo, $this->staff($mpdo));
        Mail::fake(); // forget registration's messages; this test is about the forward

        $this->forward($document, $sender, $mto, 'For funding check');

        Mail::assertSent(DocumentNotificationMail::class, 2);

        foreach ([$receivingAdmin, $receivingClerk] as $recipient) {
            Mail::assertSent(
                DocumentNotificationMail::class,
                fn (DocumentNotificationMail $mail) => $mail->hasTo($recipient->email)
                    && $mail->type === NotificationType::Forwarded
                    && $mail->document->is($document),
            );
        }

        foreach ([$sender, $bystander] as $nobody) {
            Mail::assertNotSent(DocumentNotificationMail::class, fn (DocumentNotificationMail $mail) => $mail->hasTo($nobody->email));
        }
    }

    public function test_inactive_and_unverified_accounts_are_not_emailed(): void
    {
        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');

        $inactive = $this->staff($mto);
        $inactive->forceFill(['is_active' => false])->save();

        // An address nobody proved is theirs. A typo in it would send the
        // document's details to a stranger.
        $unverified = $this->staff($mto);
        $unverified->forceFill(['email_verified_at' => null])->save();

        $document = $this->registerDocument($mpdo, $this->staff($mpdo));
        $this->forward($document, $this->admin($mpdo), $mto);

        Mail::assertNotSent(DocumentNotificationMail::class, fn (DocumentNotificationMail $mail) => $mail->hasTo($inactive->email));
        Mail::assertNotSent(DocumentNotificationMail::class, fn (DocumentNotificationMail $mail) => $mail->hasTo($unverified->email));
    }

    public function test_registering_a_document_emails_the_office_it_was_filed_with(): void
    {
        $mpdo = $this->office('MPDO');
        $admin = $this->admin($mpdo);
        $clerk = $this->staff($mpdo);

        $this->registerDocument($mpdo, $clerk);

        Mail::assertSent(
            DocumentNotificationMail::class,
            fn (DocumentNotificationMail $mail) => $mail->hasTo($admin->email) && $mail->type === NotificationType::Assigned,
        );

        // The clerk filed it; they know.
        Mail::assertNotSent(DocumentNotificationMail::class, fn (DocumentNotificationMail $mail) => $mail->hasTo($clerk->email));
    }

    /**
     * Found in QA 2026-09-24: the filing form's Remarks box is saved on the
     * document, not on the genesis leg, so the "new document" email used to
     * arrive without it.
     */
    public function test_the_new_document_email_carries_the_filing_remarks(): void
    {
        $mpdo = $this->office('MPDO');
        $admin = $this->admin($mpdo);

        $this->registerDocument($mpdo, $this->staff($mpdo));

        Mail::assertSent(DocumentNotificationMail::class, function (DocumentNotificationMail $mail) use ($admin): bool {
            if (! $mail->hasTo($admin->email)) {
                return false;
            }

            $mail->assertSeeInHtml('Initial remarks');
            $mail->assertSeeInText('Remarks:');

            return true;
        });
    }

    public function test_a_super_admin_sender_is_not_placed_in_an_office(): void
    {
        $mpdo = $this->office('MPDO', 'Planning Office');
        $mto = $this->office('MTO', 'Treasury');
        $super = $this->superAdmin();
        $receiver = $this->admin($mto);

        $document = $this->registerDocument($mpdo, $this->staff($mpdo));
        $movement = $this->forward($document, $super, $mto);

        $mail = new DocumentNotificationMail(
            NotificationType::Forwarded,
            $document->refresh(),
            $movement,
            $super,
            $receiver,
            'you belong to Treasury',
        );

        $mail->assertSeeInText("{$super->name} sent this document to Treasury.");
        $mail->assertDontSeeInText('(Planning Office)');
    }

    public function test_a_return_emails_the_originating_office_and_the_submitter_once_each(): void
    {
        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');

        $mpdoAdmin = $this->admin($mpdo);
        $submitter = $this->staff($mpdo);
        $mtoAdmin = $this->admin($mto);

        $document = $this->registerDocument($mpdo, $submitter);
        $this->forward($document, $mpdoAdmin, $mto);
        Mail::fake();

        app(TransitionDocument::class)->handle(
            document: $document->refresh(),
            action: MovementAction::Returned,
            actor: $mtoAdmin,
            remarks: 'Unsigned quotation.',
            expectedMovementId: $document->refresh()->openMovement->id,
        );

        // The submitter belongs to the originating office AND is named as the
        // submitter -- one email, not two.
        foreach ([$submitter, $mpdoAdmin] as $recipient) {
            $this->assertCount(1, Mail::sent(
                DocumentNotificationMail::class,
                fn (DocumentNotificationMail $mail) => $mail->hasTo($recipient->email) && $mail->type === NotificationType::Returned,
            ));
        }
        Mail::assertNotSent(DocumentNotificationMail::class, fn (DocumentNotificationMail $mail) => $mail->hasTo($mtoAdmin->email));
    }

    public function test_a_submitter_filing_for_another_office_is_still_emailed_on_a_return(): void
    {
        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');

        // A clerk can file against an office they do not belong to. The office
        // query alone would never reach them.
        $outsider = $this->staff($this->office('HRMO', 'HR'));
        $document = $this->registerDocument($mpdo, $outsider);
        $this->forward($document, $this->admin($mpdo), $mto);
        Mail::fake();

        app(TransitionDocument::class)->handle(
            document: $document->refresh(),
            action: MovementAction::Returned,
            actor: $this->admin($mto),
            remarks: 'Wrong form.',
            expectedMovementId: $document->refresh()->openMovement->id,
        );

        Mail::assertSent(
            DocumentNotificationMail::class,
            fn (DocumentNotificationMail $mail) => $mail->hasTo($outsider->email) && $mail->reason === 'you filed this document',
        );
    }

    public function test_a_same_office_action_sends_nothing(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $this->staff($office);

        $document = $this->registerDocument($office, $this->staff($office));
        Mail::fake();

        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Received,
            actor: $admin,
            expectedMovementId: $document->openMovement->id,
        );

        Mail::assertNothingSent();
    }

    public function test_nothing_is_sent_when_mail_is_not_configured(): void
    {
        // `log` accepts everything and delivers nothing -- writing a copy of
        // every forward into the mail log is noise, not a notification.
        config(['mail.default' => 'log']);

        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');
        $this->admin($mto);

        $this->forward($this->registerDocument($mpdo, $this->staff($mpdo)), $this->admin($mpdo), $mto);

        Mail::assertNothingSent();
    }

    public function test_the_switch_turns_email_off_and_leaves_the_bell_alone(): void
    {
        config(['cicto.notifications.email' => false]);

        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');
        $receiver = $this->admin($mto);

        $this->forward($this->registerDocument($mpdo, $this->staff($mpdo)), $this->admin($mpdo), $mto);

        Mail::assertNothingSent();
        $this->assertDatabaseHas('notifications', [
            'user_id' => $receiver->id,
            'type' => NotificationType::Forwarded->value,
        ]);
    }

    public function test_the_email_is_sent_after_the_response_not_during_the_request(): void
    {
        // Put the real deferral back: the one thing this test is about.
        $this->app->instance(DeferredCallbackCollection::class, new DeferredCallbackCollection);

        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');
        $receiver = $this->admin($mto);

        $this->forward($this->registerDocument($mpdo, $this->staff($mpdo)), $this->admin($mpdo), $mto);

        Mail::assertNothingSent();

        // What InvokeDeferredCallbacks does once the browser has its response.
        app(DeferredCallbackCollection::class)->invoke();

        Mail::assertSent(DocumentNotificationMail::class, fn (DocumentNotificationMail $mail) => $mail->hasTo($receiver->email));
    }

    public function test_a_broken_mail_server_does_not_break_the_forward(): void
    {
        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');
        $mpdoAdmin = $this->admin($mpdo);
        $receiver = $this->admin($mto);
        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        // A real smtp mailer pointed at a port nothing listens on, so the
        // failure comes from the real transport rather than a mock. A fresh
        // MailManager, because the one behind the facade is setUp()'s fake.
        config([
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 1,
            'mail.mailers.smtp.scheme' => null,
            'mail.mailers.smtp.username' => null,
            'mail.mailers.smtp.password' => null,
            'mail.mailers.smtp.timeout' => 1,
        ]);
        Mail::swap(new MailManager($this->app));
        $this->app->instance(DeferredCallbackCollection::class, new DeferredCallbackCollection);

        Log::spy();

        // Through HTTP, so the deferred send runs where it runs in production:
        // in the kernel's terminate step, after the response is built.
        $this->actingAs($mpdoAdmin)
            ->post(route('documents.transitions.store', $document), [
                'action' => 'forwarded',
                'to_office_id' => $mto->id,
                'expected_movement_id' => $document->openMovement->id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame($mto->id, $document->refresh()->openMovement->to_office_id);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $receiver->id,
            'type' => NotificationType::Forwarded->value,
        ]);

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context) => $message === 'Document notification email failed'
                && $context['user_id'] === $receiver->id)
            ->once();
    }

    public function test_the_email_says_what_arrived_and_links_to_it(): void
    {
        $mpdo = $this->office('MPDO', 'Planning Office');
        $mto = $this->office('MTO', 'Treasury');
        $sender = $this->admin($mpdo);
        $receiver = $this->admin($mto);

        $document = $this->registerDocument($mpdo, $this->staff($mpdo));
        $document->forceFill(['title' => 'Budget <b>& "quotes"'])->save();
        $movement = $this->forward($document, $sender, $mto, 'Please check the quotation_v2 figures.');

        $mail = new DocumentNotificationMail(
            NotificationType::Forwarded,
            $document->refresh(),
            $movement,
            $sender,
            $receiver,
            'you belong to Treasury',
        );

        $mail->assertHasSubject("[CICTO] Document forwarded to your office: {$document->control_number}");
        $mail->assertSeeInHtml($document->control_number);
        $mail->assertSeeInHtml("{$sender->name} (Planning Office) sent this document to Treasury.");
        $mail->assertSeeInHtml('Please check the quotation_v2 figures.');
        $mail->assertSeeInHtml(route('documents.show', $document));

        // Staff-typed text is escaped in the HTML part...
        $mail->assertSeeInHtml('Budget &lt;b&gt;', false);
        $mail->assertDontSeeInHtml('Budget <b>', false);

        // ...and left readable in the plain-text part.
        $mail->assertSeeInText('Budget <b>& "quotes"', false);
        $mail->assertSeeInText('Remarks:');
    }
}
