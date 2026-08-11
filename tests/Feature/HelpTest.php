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

    public function test_the_help_pages_require_a_session(): void
    {
        $this->get(route('help.knowledge-base'))->assertRedirect(route('login'));
    }

    public function test_a_ticket_is_emailed_when_mail_is_configured(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp', 'cicto.support.email' => 'ict@baliwag.test']);

        $this->actingAs($this->user())
            ->post(route('help.ticket.store'), [
                'subject' => 'Cannot scan a label',
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
                'subject' => 'Cannot scan a label',
                'body' => 'The camera button does nothing.',
            ]);

        $response->assertRedirect();

        // Nothing left the building...
        Mail::assertNothingSent();

        // ...and the user is told so, rather than being thanked for a ticket
        // that reached nobody.
        $response->assertSessionHas('toast.type', 'warning');
    }

    public function test_a_ticket_requires_both_a_subject_and_a_body(): void
    {
        $this->actingAs($this->user())
            ->post(route('help.ticket.store'), ['subject' => '', 'body' => ''])
            ->assertSessionHasErrors(['subject', 'body']);
    }
}
