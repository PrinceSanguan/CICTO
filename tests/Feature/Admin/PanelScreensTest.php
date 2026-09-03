<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * §4's Admin Panel screens, built from the client's designs.
 *
 * Users and Settings shipped as placeholders through Phase 3 and are real
 * screens now, so the things that matter about them are pinned here: office
 * scoping on the user list, and that Settings stores what it claims to.
 */
class PanelScreensTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    /**
     * The Office column, on the Admin panel's copy of the register.
     *
     * Same client ask of 2026-09-03 as the Super Admin screen. It repeats one
     * value down the page here, because an office admin only ever sees their
     * own department -- which is itself the answer to "whose list is this".
     */
    public function test_the_user_list_names_each_accounts_office(): void
    {
        $office = $this->office('PDC', 'City Planning and Development');
        $clerk = $this->staff($office);

        $rows = collect(
            $this->actingAs($this->admin($office))
                ->get(route('admin.users.index'))
                ->assertOk()
                ->viewData('page')['props']['users']['data'],
        )->keyBy('id');

        $this->assertSame('PDC', $rows[$clerk->id]['office']);
        $this->assertSame('City Planning and Development', $rows[$clerk->id]['office_name']);
    }

    public function test_an_office_admin_sees_only_their_own_offices_staff(): void
    {
        $mine = $this->office('MO', "Mayor's Office");
        $theirs = $this->office('SB', 'Sangguniang Bayan');

        $admin = $this->admin($mine);
        $colleague = $this->staff($mine);
        $stranger = $this->staff($theirs);

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertInertia(function ($page) use ($admin, $colleague, $stranger) {
                $emails = collect($page->toArray()['props']['users']['data'])
                    ->pluck('email');

                $this->assertTrue($emails->contains($admin->email));
                $this->assertTrue($emails->contains($colleague->email));
                $this->assertFalse(
                    $emails->contains($stranger->email),
                    'another office\'s staff leaked into the user list',
                );
            });
    }

    public function test_a_super_admin_sees_every_account(): void
    {
        $mine = $this->office('MO', "Mayor's Office");
        $theirs = $this->office('SB', 'Sangguniang Bayan');

        $this->staff($mine);
        $stranger = $this->staff($theirs);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertInertia(function ($page) use ($stranger) {
                $emails = collect($page->toArray()['props']['users']['data'])
                    ->pluck('email');

                $this->assertTrue($emails->contains($stranger->email));
            });
    }

    public function test_the_user_list_filters_by_role_and_status(): void
    {
        $office = $this->office('MO', "Mayor's Office");
        $admin = $this->admin($office);
        $clerk = $this->staff($office);

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['role' => Role::User->value]))
            ->assertInertia(function ($page) use ($admin, $clerk) {
                $emails = collect($page->toArray()['props']['users']['data'])
                    ->pluck('email');

                $this->assertTrue($emails->contains($clerk->email));
                $this->assertFalse($emails->contains($admin->email));
            });

        $clerk->forceFill(['is_active' => false])->save();

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['status' => 'inactive']))
            ->assertInertia(function ($page) use ($clerk) {
                $emails = collect($page->toArray()['props']['users']['data'])
                    ->pluck('email');

                $this->assertSame([$clerk->email], $emails->all());
            });
    }

    /**
     * The office admin's user search had no test at all, and it carries the
     * same hand-written LIKE + ESCAPE clause as the super admin's. That clause
     * used to be `escape '\'`, which MySQL rejects outright as an unterminated
     * string literal -- so this screen was a 500 on every MySQL deployment and
     * nothing here noticed.
     */
    public function test_the_admin_user_search_finds_people_and_treats_wildcards_as_text(): void
    {
        $office = $this->office('OCM', 'Office of the City Mayor');
        $admin = $this->admin($office);
        $clerk = $this->staff($office);

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['q' => $clerk->email]))
            ->assertSuccessful()
            ->assertInertia(function ($page) use ($clerk) {
                $emails = collect($page->toArray()['props']['users']['data'])->pluck('email');

                $this->assertSame([$clerk->email], $emails->all());
            });

        // A wildcard is a character somebody typed, not an instruction.
        $this->actingAs($admin)
            ->get(route('admin.users.index', ['q' => '%']))
            ->assertSuccessful()
            ->assertInertia(function ($page) {
                $this->assertSame(
                    [],
                    collect($page->toArray()['props']['users']['data'])->pluck('email')->all(),
                    'A bare % matched every user instead of being treated as text.',
                );
            });
    }

    public function test_a_plain_user_cannot_reach_the_panel(): void
    {
        $user = $this->staff($this->office('MO', "Mayor's Office"));

        foreach (['admin.users.index', 'admin.reports.index', 'admin.settings.edit'] as $route) {
            $this->actingAs($user)->get(route($route))->assertForbidden();
        }
    }

    public function test_settings_are_saved_and_read_back(): void
    {
        $admin = $this->admin($this->office('MO', "Mayor's Office"));

        $this->actingAs($admin)
            ->from(route('admin.settings.edit'))
            ->patch(route('admin.settings.update'), [
                'language' => 'en',
                'timezone' => 'Asia/Manila',
                'date_format' => 'd/m/Y',
                'session_timeout' => 30,
            ])
            ->assertRedirect(route('admin.settings.edit'));

        $admin = User::query()->findOrFail($admin->id);

        $this->assertSame('d/m/Y', $admin->preference('date_format'));
        $this->assertSame(30, $admin->preference('session_timeout'));

        $this->actingAs($admin)
            ->get(route('admin.settings.edit'))
            ->assertInertia(
                fn ($page) => $page
                    ->where('settings.date_format', 'd/m/Y')
                    ->where('settings.session_timeout', 30),
            );
    }

    /**
     * Every option offered has to be one the server will accept. A select whose
     * values fail validation is a Save button that silently never works.
     */
    public function test_settings_reject_a_value_that_is_not_offered(): void
    {
        $admin = $this->admin($this->office('MO', "Mayor's Office"));

        $this->actingAs($admin)
            ->from(route('admin.settings.edit'))
            ->patch(route('admin.settings.update'), [
                'language' => 'en',
                'timezone' => 'Europe/London',
                'date_format' => 'd/m/Y',
                'session_timeout' => 5,
            ])
            ->assertSessionHasErrors(['timezone', 'session_timeout']);
    }

    /**
     * The Session Timeout control is the one setting on that screen that has to
     * DO something.
     *
     * Laravel's own session.lifetime is a single global value, so a per-user
     * choice stored and never enforced would be a control that lies to a clerk
     * about a shared counter terminal locking itself.
     */
    public function test_an_idle_session_is_signed_out_after_the_chosen_timeout(): void
    {
        $admin = $this->admin($this->office('MO', "Mayor's Office"));
        $admin->mergePreferences(['session_timeout' => 15]);
        $admin->save();

        // First request stamps the activity marker.
        $this->actingAs($admin)->get(route('admin.settings.edit'))->assertOk();
        $this->assertAuthenticated();

        // Wind the marker back past the window. Travelling the clock forward
        // would not work here: the marker is a unix timestamp already in the
        // session, and this is precisely how it looks after a long lunch.
        session(['cicto.last_activity' => time() - (16 * 60)]);

        $this->get(route('admin.settings.edit'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_session_inside_the_window_is_left_alone(): void
    {
        $admin = $this->admin($this->office('MO', "Mayor's Office"));
        $admin->mergePreferences(['session_timeout' => 30]);
        $admin->save();

        $this->actingAs($admin)->get(route('admin.settings.edit'))->assertOk();

        session(['cicto.last_activity' => time() - (29 * 60)]);

        $this->get(route('admin.settings.edit'))->assertOk();
        $this->assertAuthenticated();
    }

    public function test_the_reports_screen_lists_what_is_still_pending(): void
    {
        $office = $this->office('MO', "Mayor's Office");
        $admin = $this->admin($office);
        $document = $this->registerDocument($office, $this->staff($office));

        $this->actingAs($admin)
            ->get(route('admin.reports.index'))
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->component('admin/reports/index')
                    ->where('pending.0.control_number', $document->control_number)
                    // 12 months of grid, zeros included, so an empty month is a
                    // point on the line rather than a gap.
                    ->count('trend', 12),
            );
    }
}
