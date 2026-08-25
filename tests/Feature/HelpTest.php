<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Help\KnowledgeBase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
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
