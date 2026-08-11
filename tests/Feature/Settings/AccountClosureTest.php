<?php

namespace Tests\Feature\Settings;

use App\Models\DocumentMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * Closing your own account must never rewrite the audit trail.
 *
 * `document_movements.actor_id` is nullOnDelete, so deleting a user who has
 * handled documents turns every "Forwarded by Maria Santos" into "Forwarded by
 * nobody" -- retroactively, with no record it ever said otherwise. And
 * `documents.created_by_id` is restrictOnDelete, so the delete throws instead,
 * after the session has already been torn down.
 */
class AccountClosureTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    public function test_closing_an_account_with_history_deactivates_and_keeps_attribution(): void
    {
        $office = $this->office();
        $clerk = $this->staff($office);
        $document = $this->registerDocument($office, $clerk);

        $actorRows = DocumentMovement::query()->where('actor_id', $clerk->id)->count();
        $this->assertGreaterThan(0, $actorRows, 'fixture should leave ledger rows');

        $this->actingAs($clerk)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertRedirect('/');

        $clerk->refresh();

        // Still there, still named on every row they touched.
        $this->assertFalse($clerk->is_active);
        $this->assertSame(
            $actorRows,
            DocumentMovement::query()->where('actor_id', $clerk->id)->count(),
            'Closing the account erased attribution from the ledger.',
        );
        $this->assertSame($clerk->id, $document->fresh()->created_by_id);
    }

    public function test_closing_an_account_with_history_does_not_error(): void
    {
        $office = $this->office();
        $clerk = $this->staff($office);
        $this->registerDocument($office, $clerk);

        // documents.created_by_id is restrictOnDelete: a real delete here is a
        // QueryException, and it used to fire after Auth::logout().
        $this->actingAs($clerk)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertRedirect('/')
            ->assertSessionHasNoErrors();

        $this->assertNotNull(User::find($clerk->id));
    }

    public function test_an_account_that_has_touched_nothing_is_deleted_outright(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertRedirect('/');

        // Nothing to preserve, so nothing is kept.
        $this->assertNull(User::find($user->id));
    }

    public function test_a_wrong_password_closes_nothing(): void
    {
        $office = $this->office();
        $clerk = $this->staff($office);

        $this->actingAs($clerk)
            ->delete(route('profile.destroy'), ['password' => 'not-the-password'])
            ->assertSessionHasErrors('password');

        $this->assertTrue($clerk->fresh()->is_active);
    }
}
