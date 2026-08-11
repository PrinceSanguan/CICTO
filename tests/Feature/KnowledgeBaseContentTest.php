<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Support\Help\KnowledgeBase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * The knowledge base has to describe THIS system.
 *
 * A help article that names a control or a status the app does not have sends
 * a reader hunting for something that is not there, and generates the support
 * call it was written to prevent. That already happened once -- the Common
 * Errors article promised an error message the app had stopped sending -- so
 * the checkable parts are pinned here rather than trusted to review.
 */
class KnowledgeBaseContentTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    /**
     * The client's design for this article listed a "Released" status. No
     * screen in the system uses that word; the final state is Completed. Every
     * heading here must be a label a reader can actually see.
     */
    public function test_every_status_named_in_the_article_is_one_the_app_displays(): void
    {
        $article = KnowledgeBase::find('document-status-explained');

        $this->assertNotNull($article);

        $public = collect(DocumentStatus::cases())
            ->map(fn (DocumentStatus $status) => $status->publicLabel());

        // The stage stepper on the document page shows the workflow names too,
        // so those count as displayed.
        $stages = collect(DocumentStatus::cases())
            ->map(fn (DocumentStatus $status) => $status->label());

        $displayed = $public->merge($stages)->unique();

        foreach ($article['sections'] as $section) {
            $this->assertTrue(
                $displayed->contains($section['title']),
                "The status article names \"{$section['title']}\", which no screen displays. ".
                'Displayed labels are: '.$displayed->sort()->implode(', '),
            );
        }
    }

    /**
     * Every public label needs an entry, or a reader who sees a pill has
     * nowhere to look it up.
     */
    public function test_every_public_status_label_has_an_entry(): void
    {
        $article = KnowledgeBase::find('document-status-explained');
        $titles = collect($article['sections'])->pluck('title');

        foreach (DocumentStatus::cases() as $status) {
            $this->assertTrue(
                $titles->contains($status->publicLabel()),
                "No entry explains the \"{$status->publicLabel()}\" pill.",
            );
        }
    }

    /**
     * The article page renders `intro`, then `steps` and/or `sections`, then
     * `closing`. An article missing all of those would render an empty card.
     */
    public function test_every_article_has_content_the_page_can_render(): void
    {
        foreach (KnowledgeBase::articles() as $article) {
            $slug = $article['slug'];

            foreach (['slug', 'title', 'summary', 'category', 'icon', 'intro'] as $key) {
                $this->assertArrayHasKey($key, $article, "{$slug} is missing {$key}");
                $this->assertNotSame('', $article[$key], "{$slug} has an empty {$key}");
            }

            $this->assertTrue(
                ! empty($article['steps']) || ! empty($article['sections']),
                "{$slug} has neither steps nor sections, so its card would be nearly empty.",
            );

            $this->assertArrayHasKey(
                $article['category'],
                KnowledgeBase::CATEGORIES,
                "{$slug} is filed under a category with no chip.",
            );
        }
    }

    /**
     * The article tells a reader to click a control by name, so the name has to
     * match what the login screen actually says.
     *
     * The design writes "Forget Password?", and that typo had propagated: the
     * screen said one thing beside the field and another below the button, and
     * the article quoted the wrong one.
     */
    public function test_the_password_article_names_the_link_the_login_screen_shows(): void
    {
        $login = (string) file_get_contents(
            resource_path('js/pages/auth/login.tsx'),
        );

        $this->assertStringNotContainsString(
            'Forget Password',
            $login,
            'The login screen still says "Forget Password".',
        );

        $article = KnowledgeBase::find('i-forgot-my-password');

        $this->assertStringNotContainsString(
            'Forget Password',
            (string) json_encode($article),
            'The article still tells users to look for "Forget Password".',
        );

        $this->assertStringContainsString(
            'Forgot Password',
            (string) json_encode($article),
        );
    }

    public function test_every_article_renders(): void
    {
        $user = $this->staff($this->office('MO', "Mayor's Office"));

        foreach (KnowledgeBase::articles() as $article) {
            $this->actingAs($user)
                ->get(route('help.article', $article['slug']))
                ->assertOk()
                ->assertInertia(
                    fn ($page) => $page
                        ->component('help/article')
                        ->where('article.slug', $article['slug']),
                );
        }
    }

    /**
     * Step 4 of the password article tells the reader to check their email. On
     * a server with no mail transport that email never arrives, so the article
     * has to say so before the steps rather than leave somebody waiting.
     */
    public function test_the_password_article_warns_when_mail_cannot_be_sent(): void
    {
        $user = $this->staff($this->office('MO', "Mayor's Office"));

        config()->set('mail.default', 'log');

        $this->actingAs($user)
            ->get(route('help.article', 'i-forgot-my-password'))
            ->assertInertia(
                fn ($page) => $page
                    ->where('support.mail_configured', false)
                    ->whereNot('article.unavailable_without_mail', null),
            );

        config()->set('mail.default', 'smtp');

        $this->actingAs($user)
            ->get(route('help.article', 'i-forgot-my-password'))
            ->assertInertia(fn ($page) => $page->where('support.mail_configured', true));
    }
}
