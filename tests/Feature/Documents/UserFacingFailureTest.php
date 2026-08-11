<?php

namespace Tests\Feature\Documents;

use App\Exceptions\StaleWorkflowStateException;
use App\Models\Document;
use App\Support\Help\KnowledgeBase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * The states a user reaches by accident, and what the app says about them.
 *
 * Each of these was found by driving the running app in a browser rather than
 * by reading code: the assertions below all passed on status codes while the
 * page a person actually saw was wrong. They are grouped here because they
 * share a cause -- a technically correct response that communicates the wrong
 * thing.
 */
class UserFacingFailureTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    /**
     * Two tabs open on the same document; the second one acts on a leg that has
     * already closed.
     *
     * The policy is what used to refuse this, because the action is no longer
     * legal from the new state -- and a policy refusal renders "This action is
     * unauthorized.", which is both a dead end and a lie about the cause. It is
     * a conflict. The knowledge base has always promised different wording.
     */
    public function test_a_stale_tab_is_told_the_document_moved_rather_than_that_it_is_unauthorized(): void
    {
        $office = $this->office('MO', "Mayor's Office");
        $next = $this->office('MTO', 'Treasury');
        $admin = $this->admin($office);

        $document = $this->registerDocument($office, $this->staff($office));

        // Both tabs rendered against this leg.
        $staleLeg = $document->openMovement->id;

        $this->actingAs($admin)
            ->post(route('documents.transitions.store', $document), [
                'action' => 'received',
                'expected_movement_id' => $staleLeg,
            ])
            ->assertRedirect();

        // The second tab posts the leg it was rendered with, which has closed.
        $response = $this->actingAs($admin)
            ->from(route('documents.show', $document))
            ->post(route('documents.transitions.store', $document), [
                'action' => 'forwarded',
                'to_office_id' => $next->id,
                'expected_movement_id' => $staleLeg,
            ]);

        $response->assertRedirect(route('documents.show', $document));
        $response->assertSessionHasErrors('action');

        $message = session('errors')->first('action');

        $this->assertStringContainsString('already moved on', $message);
        $this->assertStringNotContainsStringIgnoringCase('unauthorized', $message);
    }

    /**
     * The wording above is quoted verbatim in the knowledge base, so the two
     * cannot drift apart without failing here.
     */
    public function test_the_knowledge_base_quotes_the_message_the_app_actually_sends(): void
    {
        $article = collect(KnowledgeBase::articles())
            ->firstWhere('slug', 'common-errors');

        $this->assertNotNull($article, 'the Common Errors article went missing');

        $promised = 'This document has already moved on.';

        $this->assertStringContainsString(
            $promised,
            (string) json_encode($article),
            'the article documents an error message the app no longer sends',
        );

        $this->assertStringContainsString(
            rtrim($promised, '.'),
            (new StaleWorkflowStateException)->getMessage(),
        );
    }

    /**
     * A clerk who opens another office's document gets a 403. That is correct,
     * and it used to render as Laravel's unstyled page with no link back into
     * the app -- a dead end reached by an ordinary mistake.
     */
    public function test_a_cross_office_403_renders_a_branded_page_with_a_way_back(): void
    {
        $mine = $this->office('MO', "Mayor's Office");
        $theirs = $this->office('SB', 'Sangguniang Bayan');

        $document = $this->registerDocument($theirs, $this->staff($theirs));

        $response = $this->actingAs($this->staff($mine))
            ->get(route('documents.show', $document));

        $response->assertStatus(403);
        $response->assertInertia(
            fn ($page) => $page
                ->component('error')
                ->where('status', 403)
                // Drives which destination the button offers.
                ->where('authenticated', true),
        );
    }

    public function test_a_signed_out_visitor_hitting_a_missing_page_is_pointed_at_sign_in(): void
    {
        // A public URL: /documents/* is behind auth and would redirect to the
        // login screen before ever reaching a 404.
        $response = $this->get('/no-such-page');

        $response->assertStatus(404);
        $response->assertInertia(
            fn ($page) => $page
                ->component('error')
                ->where('authenticated', false),
        );
    }

    /**
     * The same miss, while signed in.
     *
     * A URL matching no route never reaches session middleware, so the handler
     * could not see the guard and told signed-in users to go and sign in. The
     * fallback route exists to put the miss back inside the web stack.
     */
    public function test_a_signed_in_user_hitting_a_missing_page_is_offered_the_way_back(): void
    {
        $response = $this->actingAs($this->staff($this->office('MO', "Mayor's Office")))
            ->get('/no-such-page');

        $response->assertStatus(404);
        $response->assertInertia(
            fn ($page) => $page
                ->component('error')
                ->where('authenticated', true),
        );
    }

    /**
     * Every list in the app has a Department column. Once a document is
     * completed there is no open leg, and the column read as an em dash -- for
     * a document whose own timeline named three offices.
     */
    public function test_a_finished_document_still_names_the_office_it_finished_at(): void
    {
        $office = $this->office('MO', "Mayor's Office");
        $admin = $this->admin($office);
        $document = $this->registerDocument($office, $this->staff($office));

        foreach (['received', 'approved', 'completed'] as $action) {
            $this->actingAs($admin)
                ->post(route('documents.transitions.store', $document), [
                    'action' => $action,
                    'expected_movement_id' => $document->fresh()->openMovement?->id,
                ])
                ->assertRedirect();
        }

        $document = Document::query()->findOrFail($document->id);

        $this->assertNull($document->openMovement, 'a completed document holds no open leg');

        $this->actingAs($admin)
            ->get(route('documents.show', $document))
            ->assertInertia(
                fn ($page) => $page
                    ->where('document.tracking.resting_office', "Mayor's Office")
                    ->where('document.tracking.is_open', false),
            );

        $this->actingAs($admin)
            ->get(route('documents.index'))
            ->assertInertia(
                fn ($page) => $page->where(
                    'documents.data.0.resting_office',
                    "Mayor's Office",
                ),
            );
    }
}
