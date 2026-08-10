<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\TransitionDocument;
use App\Enums\MovementAction;
use App\Enums\Role;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    public function test_a_plain_user_gets_403_on_an_admin_url_rather_than_a_hidden_button(): void
    {
        $office = $this->office();

        $this->actingAs($this->staff($office))
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }

    public function test_an_admin_cannot_reach_the_super_admin_panel(): void
    {
        $office = $this->office();

        $this->actingAs($this->admin($office))
            ->get(route('super-admin.dashboard'))
            ->assertForbidden();
    }

    public function test_a_super_admin_reaches_both_panels(): void
    {
        $superAdmin = $this->superAdmin();

        $this->actingAs($superAdmin)->get(route('admin.dashboard'))->assertOk();
        $this->actingAs($superAdmin)->get(route('super-admin.dashboard'))->assertOk();
    }

    public function test_a_deactivated_account_is_logged_out_of_every_authenticated_page(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $this->registerDocument($office, $this->staff($office));

        $admin->forceFill(['is_active' => false])->save();

        // Deactivating has to lock the account out everywhere, not only on the
        // routes that happen to run a policy. Before EnsureAccountIsActive the
        // list, the dashboard and the label printer all still returned 200 for
        // a deactivated user holding a live session.
        foreach ([
            route('admin.dashboard'),
            route('dashboard'),
            route('documents.index'),
            route('documents.labels.print'),
        ] as $url) {
            $this->actingAs($admin)->get($url)->assertRedirect(route('login'));
        }

        $this->assertGuest();
    }

    public function test_an_admin_cannot_see_a_document_their_office_never_handled(): void
    {
        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');

        $foreign = $this->registerDocument($mto, $this->staff($mto));

        $this->actingAs($this->admin($mpdo))
            ->get(route('documents.show', $foreign))
            ->assertForbidden();
    }

    public function test_an_office_keeps_seeing_a_document_after_forwarding_it_away(): void
    {
        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');
        $mpdoAdmin = $this->admin($mpdo);

        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Forwarded,
            actor: $mpdoAdmin,
            toOfficeId: $mto->id,
            expectedMovementId: $document->openMovement->id,
        );

        // The visibility predicate is an EXISTS over movements, matching either
        // from_office_id or to_office_id -- so handing a folder on does not
        // erase your own record of it.
        $this->actingAs($mpdoAdmin)
            ->get(route('documents.show', $document))
            ->assertOk();
    }

    public function test_a_submitter_can_always_see_their_own_document(): void
    {
        $mpdo = $this->office('MPDO');
        $clerk = $this->staff($mpdo);

        $document = $this->registerDocument($mpdo, $clerk);

        $this->actingAs($clerk)
            ->get(route('documents.show', $document))
            ->assertOk();
    }

    public function test_the_document_list_is_scoped_by_office(): void
    {
        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');

        $mine = $this->registerDocument($mpdo, $this->staff($mpdo));
        $theirs = $this->registerDocument($mto, $this->staff($mto));

        $visible = Document::query()
            ->visibleTo($this->admin($mpdo))
            ->pluck('id');

        $this->assertTrue($visible->contains($mine->id));
        $this->assertFalse($visible->contains($theirs->id));
    }

    public function test_a_role_field_posted_to_login_cannot_escalate_privilege(): void
    {
        $office = $this->office();
        $user = User::factory()->create([
            'email' => 'clerk@example.test',
            'password' => 'password',
            'role' => Role::User,
            'office_id' => $office->id,
        ]);

        $this->post(route('login'), [
            'email' => 'clerk@example.test',
            'password' => 'password',
            'role' => 'super_admin',
        ])->assertRedirect();

        // The portal is presentation only; authorization reads the database row.
        $this->assertSame(Role::User, $user->fresh()->role);
        $this->actingAs($user->fresh())->get(route('super-admin.dashboard'))->assertForbidden();
    }

    public function test_role_and_office_cannot_be_mass_assigned(): void
    {
        $office = $this->office();

        $user = new User;
        $user->fill([
            'name' => 'Someone',
            'email' => 'someone@example.test',
            'password' => 'password',
            'role' => Role::SuperAdmin->value,
            'office_id' => $office->id,
        ]);

        // #[Fillable] is a whitelist, so these two are excluded by construction.
        $this->assertSame(Role::User, $user->role);
        $this->assertNull($user->office_id);
    }

    public function test_the_signed_storage_route_that_bypasses_policies_is_not_registered(): void
    {
        // config/filesystems.php local disk has serve => false. With serve =>
        // true Laravel registers GET /storage/{path}, which streams files
        // without consulting DocumentPolicy and without writing an audit row.
        $this->assertFalse(Route::has('storage.local'));
    }

    public function test_a_quarantined_user_cannot_register_a_document(): void
    {
        $user = User::factory()->create(['role' => Role::User, 'office_id' => null]);

        $this->assertTrue($user->isQuarantined());
        $this->assertFalse($user->can('create', Document::class));

        $this->actingAs($user)->get(route('documents.create'))->assertForbidden();
    }

    public function test_the_three_login_entry_points_are_distinguishable(): void
    {
        // §3 asks for separate entry points. All three render the same page
        // against the same guard and POST to the same URL -- only the `portal`
        // prop differs, which is what makes them presentation and not a second
        // authentication path.
        foreach ([
            'login' => 'user',
            'login.admin' => 'admin',
            'login.super-admin' => 'super-admin',
        ] as $routeName => $expectedPortal) {
            $this->get(route($routeName))
                ->assertOk()
                ->assertInertia(
                    fn ($page) => $page
                        ->component('auth/login')
                        ->where('portal', $expectedPortal),
                );
        }
    }
}
