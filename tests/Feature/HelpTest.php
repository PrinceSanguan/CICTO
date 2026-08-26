<?php

namespace Tests\Feature;

use App\Mail\SupportTicketMail;
use App\Models\User;
use App\Support\Help\KnowledgeBase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * §23 Help & Support.
 *
 * The load-bearing test here is the last one: this deployment has no outgoing
 * mail (client question B3), and a ticket form that says "sent" when nothing
 * was sent is worse than no form at all.
 */
class HelpTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create();
    }

    public function test_the_help_pages_are_reachable(): void
    {
        $user = $this->user();

        foreach ([
            route('help.index'),
            route('help.knowledge-base'),
            route('help.contact'),
            route('help.ticket'),
        ] as $url) {
            $this->actingAs($user)->get($url)->assertOk();
        }
    }

    public function test_every_listed_article_actually_opens(): void
    {
        $user = $this->user();

        // A knowledge base whose links 404 is worse than an empty one.
        foreach (KnowledgeBase::articles() as $article) {
            $this->actingAs($user)
                ->get(route('help.article', $article['slug']))
                ->assertOk();
        }

        $this->actingAs($user)
            ->get(route('help.article', 'no-such-article'))
            ->assertNotFound();
    }

    public function test_every_article_belongs_to_a_declared_category(): void
    {
        foreach (KnowledgeBase::articles() as $article) {
            $this->assertArrayHasKey(
                $article['category'],
                KnowledgeBase::CATEGORIES,
                "{$article['slug']} is filed under an unknown category.",
            );
        }
    }

    /**
     * The landing page's main navigation links Help, so a visitor with no
     * account must be able to READ it. Answering an FAQ request with /login is
     * what this asserts can never come back.
     */
    public function test_a_guest_can_read_the_help_pages(): void
    {
        foreach ([
            route('help.index'),
            route('help.knowledge-base'),
            route('help.contact'),
            route('help.article', KnowledgeBase::articles()[0]['slug']),
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    /**
     * Filing is the other half. submitTicket reads `from`, `name` and `office`
     * off the session, so an anonymous ticket is not a thing that can exist --
     * both the form and the endpoint stay behind auth.
     */
    public function test_a_guest_cannot_file_a_ticket(): void
    {
        $this->get(route('help.ticket'))->assertRedirect(route('login'));

        $this->post(route('help.ticket.store'), [
            'name' => 'Nobody',
            'email' => 'nobody@example.test',
            'issue_type' => 'Something else',
            'body' => 'Filed with no session at all.',
        ])->assertRedirect(route('login'));
    }

    public function test_a_ticket_is_emailed_when_mail_is_configured(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp', 'cicto.support.email' => 'ict@baliwag.test']);

        $this->actingAs($this->user())
            ->post(route('help.ticket.store'), [
                'name' => 'Emarie Alonzo',
                'email' => 'emarie@example.test',
                'issue_type' => 'QR code will not scan',
                'body' => 'The camera button does nothing.',
            ])
            ->assertRedirect()
            ->assertSessionHas('toast.type', 'success');

        Mail::assertSentCount(1);
    }

    /*
     * The screenshot dropzone. It said "uploads are not enabled on this
     * server" until 2026-08-26, when the client's comp replaced that with
     * "Click to upload or drag and drop" -- copy that is only true if the file
     * is actually kept, which is what these two pin.
     */
    public function test_a_screenshot_is_stored_and_attached_to_the_ticket(): void
    {
        Mail::fake();
        Storage::fake('documents');
        config(['mail.default' => 'smtp', 'cicto.support.email' => 'ict@baliwag.test']);

        $this->actingAs($this->user())
            ->post(route('help.ticket.store'), [
                'name' => 'Emarie Alonzo',
                'email' => 'emarie@example.test',
                'issue_type' => 'QR code will not scan',
                'body' => 'The camera button does nothing.',
                'screenshot' => UploadedFile::fake()->image('problem.png'),
            ])
            ->assertRedirect()
            ->assertSessionHas('toast.type', 'success');

        // The file survived the request...
        $stored = Storage::disk('documents')->allFiles();
        $this->assertCount(1, $stored);
        $this->assertStringStartsWith('support-tickets/', $stored[0]);

        // ...and travelled with the mail rather than only sitting on disk.
        Mail::assertSent(SupportTicketMail::class, function (SupportTicketMail $mail) use ($stored) {
            return $mail->screenshotPath === Storage::disk('documents')->path($stored[0]);
        });
    }

    public function test_a_ticket_without_a_screenshot_still_files(): void
    {
        Mail::fake();
        Storage::fake('documents');
        config(['mail.default' => 'smtp', 'cicto.support.email' => 'ict@baliwag.test']);

        $this->actingAs($this->user())
            ->post(route('help.ticket.store'), [
                'name' => 'Emarie Alonzo',
                'email' => 'emarie@example.test',
                'issue_type' => 'Something else',
                'body' => 'No screenshot to give.',
            ])
            ->assertRedirect()
            ->assertSessionHas('toast.type', 'success');

        $this->assertSame([], Storage::disk('documents')->allFiles());

        Mail::assertSent(SupportTicketMail::class, function (SupportTicketMail $mail) {
            return $mail->screenshotPath === null;
        });
    }

    public function test_a_non_image_screenshot_is_refused(): void
    {
        Storage::fake('documents');

        $this->actingAs($this->user())
            ->post(route('help.ticket.store'), [
                'name' => 'Emarie Alonzo',
                'email' => 'emarie@example.test',
                'issue_type' => 'Something else',
                'body' => 'Attaching the wrong thing.',
                'screenshot' => UploadedFile::fake()->create('payload.php', 8),
            ])
            ->assertSessionHasErrors('screenshot');

        $this->assertSame([], Storage::disk('documents')->allFiles());
    }

    public function test_a_ticket_never_claims_to_be_sent_when_mail_is_not_configured(): void
    {
        Mail::fake();
        config(['mail.default' => 'log', 'cicto.support.email' => 'ict@baliwag.test']);

        $response = $this->actingAs($this->user())
            ->post(route('help.ticket.store'), [
                'name' => 'Emarie Alonzo',
                'email' => 'emarie@example.test',
                'issue_type' => 'QR code will not scan',
                'body' => 'The camera button does nothing.',
            ]);

        $response->assertRedirect();

        // Nothing left the building...
        Mail::assertNothingSent();

        // ...and the user is told so, rather than being thanked for a ticket
        // that reached nobody.
        $response->assertSessionHas('toast.type', 'warning');
    }

    public function test_a_ticket_requires_an_issue_type_and_a_body(): void
    {
        $this->actingAs($this->user())
            ->post(route('help.ticket.store'), ['issue_type' => '', 'body' => ''])
            ->assertSessionHasErrors(['name', 'email', 'issue_type', 'body']);
    }
}
