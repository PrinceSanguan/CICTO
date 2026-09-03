<?php

namespace Tests\Feature\SuperAdmin;

use App\Enums\Role;
use App\Enums\SecurityEventType;
use App\Http\Requests\SuperAdmin\StoreUserRequest;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * §4's Manage Users screen, and the capability it introduces.
 *
 * Creating an account is the operation that hands somebody access to a
 * municipal register, so the interesting assertions here are the refusals.
 */
class ManageUsersTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    private const PASSWORD = 'Correct-Horse-9!battery';

    public function test_a_super_admin_creates_an_account_that_can_sign_in(): void
    {
        $office = $this->office('MO', "Mayor's Office");

        $this->actingAs($this->superAdmin())
            ->from(route('super-admin.users.index'))
            ->post(route('super-admin.users.store'), [
                'name' => 'Emarie Alonzo',
                'email' => 'emarie@baliwag.gov.ph',
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
                'role' => Role::Admin->value,
                'office_id' => $office->id,
            ])
            ->assertRedirect(route('super-admin.users.index'));

        $created = User::query()->where('email', 'emarie@baliwag.gov.ph')->firstOrFail();

        $this->assertSame(Role::Admin, $created->role);
        $this->assertSame($office->id, $created->office_id);
        $this->assertTrue($created->is_active);
        $this->assertTrue(Hash::check(self::PASSWORD, $created->password));

        /*
         * Verified at creation, deliberately. The `verified` middleware gates
         * the whole app and this deployment cannot send mail, so an unverified
         * account would be one nobody could ever sign in to.
         */
        $this->assertNotNull($created->email_verified_at);

        $this->post(route('logout'));

        $this->post(route('login.store'), [
            'email' => 'emarie@baliwag.gov.ph',
            'password' => self::PASSWORD,
        ]);

        $this->assertAuthenticatedAs($created);
    }

    public function test_creating_an_account_is_written_to_the_security_log(): void
    {
        $office = $this->office('MO', "Mayor's Office");
        $actor = $this->superAdmin();

        $this->actingAs($actor)->post(route('super-admin.users.store'), [
            'name' => 'Emarie Alonzo',
            'email' => 'emarie@baliwag.gov.ph',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'role' => Role::Admin->value,
            'office_id' => $office->id,
        ]);

        $event = SecurityEvent::query()
            ->where('type', SecurityEventType::UserCreated->value)
            ->firstOrFail();

        $this->assertSame('emarie@baliwag.gov.ph', $event->subject_label);
        $this->assertStringContainsString($actor->name, $event->summary);
    }

    /**
     * An Admin can arrange people inside their own office through
     * AssignUserRole, but must not be able to mint access.
     */
    public function test_an_admin_cannot_reach_the_screen_or_create_an_account(): void
    {
        $office = $this->office('MO', "Mayor's Office");
        $admin = $this->admin($office);

        $this->actingAs($admin)
            ->get(route('super-admin.users.index'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('super-admin.users.store'), [
                'name' => 'Escalation',
                'email' => 'escalation@baliwag.gov.ph',
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
                'role' => Role::SuperAdmin->value,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'escalation@baliwag.gov.ph']);
    }

    /**
     * The register says which office each account belongs to.
     *
     * The client asked for this on 2026-09-03 looking at the Manage Users
     * screen: "dapat meron dito column kung anong office yung mga users".
     * Both halves ship -- the code, which is what appears in control numbers
     * and on printed labels, and the full name, because "PDC" does not answer
     * the question for somebody who came here to look it up.
     */
    public function test_the_register_names_each_accounts_office(): void
    {
        $office = $this->office('PDC', 'City Planning and Development');
        $clerk = $this->staff($office);
        $superAdmin = $this->superAdmin();

        $rows = collect(
            $this->actingAs($superAdmin)
                ->get(route('super-admin.users.index'))
                ->assertOk()
                ->viewData('page')['props']['users']['data'],
        )->keyBy('id');

        $this->assertSame('PDC', $rows[$clerk->id]['office']);
        $this->assertSame('City Planning and Development', $rows[$clerk->id]['office_name']);

        // A Super Admin genuinely belongs to no office, so the column has
        // nothing to print rather than missing data.
        $this->assertNull($rows[$superAdmin->id]['office']);
        $this->assertNull($rows[$superAdmin->id]['office_name']);
    }

    public function test_a_non_super_admin_role_must_be_given_an_office(): void
    {
        $this->actingAs($this->superAdmin())
            ->from(route('super-admin.users.index'))
            ->post(route('super-admin.users.store'), [
                'name' => 'No Office',
                'email' => 'nooffice@baliwag.gov.ph',
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
                'role' => Role::Admin->value,
            ])
            ->assertSessionHasErrors('office_id');

        $this->assertDatabaseMissing('users', ['email' => 'nooffice@baliwag.gov.ph']);
    }

    /**
     * An account created here has at least as much access as one created
     * anywhere else, so it is held to the same password profile.
     *
     * The password asserted below is short enough to be refused in EVERY
     * environment. The full production profile -- min 12, mixed case, numbers,
     * symbols -- is applied through Password::defaults(), which
     * AppServiceProvider deliberately relaxes outside production so local
     * seeding is not a chore; asserting the strict rules here would only be
     * testing the environment, not this screen.
     */
    public function test_a_weak_password_is_refused(): void
    {
        $office = $this->office('MO', "Mayor's Office");

        $this->actingAs($this->superAdmin())
            ->from(route('super-admin.users.index'))
            ->post(route('super-admin.users.store'), [
                'name' => 'Weak',
                'email' => 'weak@baliwag.gov.ph',
                'password' => 'short',
                'password_confirmation' => 'short',
                'role' => Role::Admin->value,
                'office_id' => $office->id,
            ])
            ->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'weak@baliwag.gov.ph']);
    }

    /**
     * The rule delegates rather than hard-coding a strength, so a deployment
     * that tightens Password::defaults() tightens this screen with it.
     */
    public function test_the_password_rule_follows_the_application_default(): void
    {
        $rules = (new StoreUserRequest)->rules();

        $this->assertContains('confirmed', $rules['password']);

        $usesDefaults = collect($rules['password'])->contains(
            fn ($rule) => $rule instanceof Password,
        );

        $this->assertTrue(
            $usesDefaults,
            'The password rule must go through Password::defaults() so it '.
            'follows whatever the deployment enforces.',
        );
    }

    public function test_a_duplicate_email_is_refused(): void
    {
        $office = $this->office('MO', "Mayor's Office");
        $existing = $this->staff($office);

        $this->actingAs($this->superAdmin())
            ->from(route('super-admin.users.index'))
            ->post(route('super-admin.users.store'), [
                'name' => 'Duplicate',
                'email' => $existing->email,
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
                'role' => Role::Admin->value,
                'office_id' => $office->id,
            ])
            ->assertSessionHasErrors('email');

        $this->assertSame(
            1,
            User::query()->where('email', $existing->email)->count(),
        );
    }

    public function test_the_list_searches_by_name_and_email(): void
    {
        $office = $this->office('MO', "Mayor's Office");
        $target = $this->staff($office);

        $this->actingAs($this->superAdmin())
            ->get(route('super-admin.users.index', ['q' => $target->email]))
            ->assertInertia(function ($page) use ($target) {
                $emails = collect($page->toArray()['props']['users']['data'])
                    ->pluck('email');

                $this->assertSame([$target->email], $emails->all());
            });
    }

    /**
     * The user search runs raw SQL with an explicit ESCAPE clause, and it used
     * to spell that clause `escape '\'` -- which PostgreSQL and SQLite accept
     * as a one-character string and MySQL reads as an unterminated literal,
     * failing with a 1064 syntax error. The only way to catch that here is to
     * exercise the search on a driver that is not SQLite, which CI does; what
     * this test can do is make sure a search that hits the escaping path runs
     * at all, on whatever driver it is given.
     *
     * @param  string  $needle  a term containing a LIKE metacharacter
     */
    #[DataProvider('metacharacterTerms')]
    public function test_the_search_survives_like_metacharacters(string $needle): void
    {
        $office = $this->office('OCM', 'Office of the City Mayor');
        $this->staff($office);

        $this->actingAs($this->superAdmin())
            ->get(route('super-admin.users.index', ['q' => $needle]))
            ->assertSuccessful()
            ->assertInertia(function ($page) use ($needle) {
                // The point is not what comes back, it is that a wildcard is
                // treated as text: none of these appear in a seeded email.
                $this->assertSame(
                    [],
                    collect($page->toArray()['props']['users']['data'])->pluck('email')->all(),
                    "Searching for [{$needle}] matched rows it should not have.",
                );
            });
    }

    /** @return array<string, array{string}> */
    public static function metacharacterTerms(): array
    {
        return [
            'percent wildcard' => ['%'],
            'underscore wildcard' => ['_'],
            'the escape character itself' => ['!'],
            'backslash' => ['\\'],
            'a wildcard inside a word' => ['a%b'],
        ];
    }

    /**
     * Every other door into users.email lower-cases it: config/fortify.php sets
     * `lowercase_usernames`, so Fortify canonicalises on sign-in and on the
     * reset request, and `cicto:user` lower-cases its argument. This screen did
     * not, and the asymmetry had a long fuse.
     *
     * An address stored with capitals is looked up in lower case at sign-in. On
     * PostgreSQL, where `=` is case-sensitive, the lookup finds nothing: the
     * account cannot be signed in to and the password is not the reason, so
     * setting a new password for them does not fix it either. The console
     * fallback then lower-cases, finds no match, and takes the CREATE branch --
     * a second account for the same person.
     */
    public function test_the_email_address_is_stored_in_lower_case(): void
    {
        $office = $this->office('OCM', 'Office of the City Mayor');

        $this->actingAs($this->superAdmin())
            ->post(route('super-admin.users.store'), [
                'name' => 'Maria Santos',
                'email' => '  Maria.Santos@Baliwag.Gov.PH ',
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
                'role' => Role::Admin->value,
                'office_id' => $office->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'maria.santos@baliwag.gov.ph']);
        $this->assertDatabaseMissing('users', ['email' => '  Maria.Santos@Baliwag.Gov.PH ']);
    }

    /**
     * And the unique index therefore does its job: the same address in
     * different case is the same account, not a second one.
     */
    public function test_a_duplicate_email_in_a_different_case_is_refused(): void
    {
        $office = $this->office('OCM', 'Office of the City Mayor');
        $existing = $this->staff($office);

        $this->actingAs($this->superAdmin())
            ->from(route('super-admin.users.index'))
            ->post(route('super-admin.users.store'), [
                'name' => 'Duplicate',
                'email' => mb_strtoupper($existing->email),
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
                'role' => Role::Admin->value,
                'office_id' => $office->id,
            ])
            ->assertSessionHasErrors('email');

        $this->assertSame(1, User::query()->where('email', $existing->email)->count());
    }

    /**
     * Two panels can be open at once on this screen, and both are a form full
     * of password boxes. `label[for]` resolves to the FIRST matching id in the
     * document, so a shared id put the caret in the wrong form's box -- the
     * administrator types a password into the Add-account panel and submits a
     * reset with an empty one. Asserted against the source, because it is a
     * property of the markup rather than of a response.
     */
    public function test_the_two_password_panels_do_not_share_input_ids(): void
    {
        $source = (string) file_get_contents(
            resource_path('js/pages/super-admin/users/index.tsx'),
        );

        foreach (['reset-password', 'reset-password_confirmation', 'reset-your_password'] as $id) {
            $this->assertStringContainsString(
                'id="'.$id.'"',
                $source,
                "The reset panel's fields must carry ids of their own; [{$id}] is missing.",
            );
        }
    }
}
