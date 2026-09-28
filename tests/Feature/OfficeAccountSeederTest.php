<?php

namespace Tests\Feature;

use App\Actions\Documents\TransitionDocument;
use App\Enums\MovementAction;
use App\Enums\Role;
use App\Enums\SecurityEventType;
use App\Models\Office;
use App\Models\SecurityEvent;
use App\Models\User;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\OfficeAccountSeeder;
use Database\Seeders\OfficeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * The rollout account set.
 *
 * The assertion that matters is the last shape of the first one: §5's "send to"
 * dropdown offers every ACTIVE office, and DocumentPolicy grants office-scoped
 * read to Role::Admin alone -- so an office in that dropdown with no Admin is a
 * destination a document can reach and nobody can open. Everything else here
 * exists to keep that property true on a live database that is re-seeded more
 * than once.
 */
class OfficeAccountSeederTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The seeder writes the credential sheet to the private local disk.
        Storage::fake('local');

        $this->seed([OfficeSeeder::class, DocumentTypeSeeder::class]);
    }

    public function test_every_active_office_gets_two_admins_and_one_clerk(): void
    {
        $this->seed(OfficeAccountSeeder::class);

        $offices = Office::query()->active()->get();

        // Guards against a seeder that "passes" by finding no offices at all.
        $this->assertGreaterThan(40, $offices->count());
        $this->assertSame($offices->count() * 3, User::query()->count());

        foreach ($offices as $office) {
            $accounts = User::query()->where('office_id', $office->id)->get();

            $this->assertCount(3, $accounts, "{$office->code} did not get its three accounts.");

            $admins = $accounts->where('role', Role::Admin);

            $this->assertSame(
                2,
                $admins->count(),
                "{$office->code} does not have two Admins.",
            );
            $this->assertSame(1, $accounts->where('role', Role::User)->count());

            // The name is what the audit trail shows. Two Admins with the same
            // name would be two accounts the trail still cannot tell apart,
            // which is the one thing the second Admin is for.
            $this->assertCount(
                2,
                $admins->pluck('name')->unique(),
                "{$office->code}'s two Admins share a name, so the audit trail cannot tell them apart.",
            );

            foreach ($accounts as $account) {
                $this->assertTrue($account->is_active);

                // User implements MustVerifyEmail and `verified` gates every
                // protected route group. An unverified account here is one
                // nobody could ever sign in to, and no verification mail can
                // reach these addresses to fix it.
                $this->assertTrue($account->hasVerifiedEmail());
            }
        }
    }

    public function test_an_inactive_office_gets_nothing(): void
    {
        $this->seed(OfficeAccountSeeder::class);

        // CSWD is the duplicate of CSWDO that OfficeSeeder ships deactivated.
        // It is absent from the dropdown, so staffing it would only produce
        // accounts that can never receive anything.
        $cswd = Office::query()->where('code', 'CSWD')->firstOrFail();

        $this->assertFalse($cswd->is_active);
        $this->assertSame(0, User::query()->where('office_id', $cswd->id)->count());
    }

    public function test_every_office_the_submit_form_offers_has_somebody_who_can_receive(): void
    {
        $this->seed(OfficeAccountSeeder::class);

        $offered = [];

        $this->actingAs($this->seeded('ocm.clerk'))
            ->get(route('documents.create'))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$offered): void {
                /** @var list<array{id: int, code: string, name: string}> $offices */
                $offices = $page->toArray()['props']['offices'];

                $offered = $offices;
            });

        // Not just "some offices with admins behind them": the form has to
        // offer every active office, or a department silently stops being a
        // destination and nobody finds out until somebody looks for it.
        $this->assertCount(Office::query()->active()->count(), $offered);

        foreach ($offered as $office) {
            $this->assertSame(
                2,
                User::query()
                    ->where('office_id', $office['id'])
                    ->where('role', Role::Admin)
                    ->where('is_active', true)
                    ->count(),
                "{$office['code']} is offered by the submit form with no active Admin behind it.",
            );
        }
    }

    public function test_every_office_the_forward_picker_offers_has_somebody_who_can_receive(): void
    {
        $this->seed(OfficeAccountSeeder::class);

        // The second of the two pickers, and the one §5 calls "passing" a
        // document. Submit chooses where a folder STARTS; this one chooses
        // where it goes next, and it is the one used for the rest of the
        // document's life -- so it needs the same guarantee, asserted
        // separately because it is a different query on a different screen.
        $ocm = Office::query()->where('code', 'OCM')->firstOrFail();
        $document = $this->registerDocument($ocm, $this->seeded('ocm.clerk'));

        $offered = [];

        $this->actingAs($this->seeded('ocm.admin'))
            ->get(route('documents.show', $document))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$offered): void {
                /** @var list<array{id: int, name: string}> $offices */
                $offices = $page->toArray()['props']['offices'];

                $offered = $offices;
            });

        // Everywhere the folder can go, minus where it already is: the genesis
        // leg puts it in its own originating office.
        $this->assertCount(Office::query()->active()->count() - 1, $offered);
        $this->assertNotContains($ocm->id, array_column($offered, 'id'));

        foreach ($offered as $office) {
            $this->assertSame(
                2,
                User::query()
                    ->where('office_id', $office['id'])
                    ->where('role', Role::Admin)
                    ->where('is_active', true)
                    ->count(),
                "{$office['name']} can be forwarded to with no active Admin behind it.",
            );
        }
    }

    public function test_an_office_admin_can_open_and_receive_a_document_forwarded_to_them(): void
    {
        $this->seed(OfficeAccountSeeder::class);

        $ocm = Office::query()->where('code', 'OCM')->firstOrFail();
        $treasury = Office::query()->where('code', 'TREA')->firstOrFail();

        $document = $this->registerDocument($ocm, $this->seeded('ocm.clerk'));

        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Forwarded,
            actor: $this->seeded('ocm.admin'),
            toOfficeId: $treasury->id,
            expectedMovementId: $document->openMovement?->id,
        );

        $receiver = $this->seeded('trea.admin');

        $this->actingAs($receiver)
            ->get(route('documents.show', $document))
            ->assertOk();

        $this->assertTrue(
            $receiver->can('act', [$document->refresh(), MovementAction::Received]),
            'The receiving office cannot acknowledge a document sitting in its own inbox.',
        );
    }

    public function test_a_clerk_can_submit_but_cannot_open_another_office_document(): void
    {
        $this->seed(OfficeAccountSeeder::class);

        $ocm = Office::query()->where('code', 'OCM')->firstOrFail();
        $document = $this->registerDocument($ocm, $this->seeded('ocm.clerk'));

        // The User half of the pair is the submitter, not a second Admin: an
        // office's clerk has no office-wide read, which is the boundary §11's
        // role split is for.
        $this->actingAs($this->seeded('trea.clerk'))
            ->get(route('documents.show', $document))
            ->assertForbidden();
    }

    /**
     * Asked for on 2026-09-24: two Admins per office with the same powers, so
     * that the audit trail shows WHICH of them approved a document.
     */
    public function test_both_admins_can_act_and_the_trail_names_the_one_who_did(): void
    {
        $this->seed(OfficeAccountSeeder::class);

        $ocm = Office::query()->where('code', 'OCM')->firstOrFail();
        $treasury = Office::query()->where('code', 'TREA')->firstOrFail();

        $document = $this->registerDocument($ocm, $this->seeded('ocm.clerk'));

        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Forwarded,
            actor: $this->seeded('ocm.admin'),
            toOfficeId: $treasury->id,
            expectedMovementId: $document->openMovement?->id,
        );

        $first = $this->seeded('trea.admin');
        $second = $this->seeded('trea.admin2');

        // Same rights: either of them can take the folder in.
        foreach ([$first, $second] as $admin) {
            $this->assertTrue(
                $admin->can('act', [$document->refresh(), MovementAction::Received]),
                "{$admin->email} cannot receive a document sent to their own office.",
            );
        }

        // One receives, the OTHER completes -- neither is locked out by the
        // step the other one took.
        app(TransitionDocument::class)->handle(
            document: $document->refresh(),
            action: MovementAction::Received,
            actor: $second,
            expectedMovementId: $document->refresh()->openMovement?->id,
        );

        $this->assertTrue($first->can('act', [$document->refresh(), MovementAction::Completed]));

        app(TransitionDocument::class)->handle(
            document: $document->refresh(),
            action: MovementAction::Completed,
            actor: $first,
            expectedMovementId: $document->refresh()->openMovement?->id,
        );

        $this->actingAs($first)
            ->get(route('documents.show', $document))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('timeline.2.action', 'received')
                ->where('timeline.2.actor', 'TREA Admin 2')
                ->where('timeline.2.to_office', $treasury->name)
                ->where('timeline.3.action', 'completed')
                ->where('timeline.3.actor', 'TREA Admin')
                ->where('timeline.3.to_office', $treasury->name));
    }

    /**
     * The other thing a second Admin buys. With self-approval off (the
     * default, client question A6), an Admin who FILES a document cannot also
     * decide on it -- and in an office with one Admin, that document had
     * nobody at home to decide on it. The second Admin can.
     */
    public function test_the_second_admin_can_decide_on_what_the_first_one_filed(): void
    {
        $this->seed(OfficeAccountSeeder::class);

        $ocm = Office::query()->where('code', 'OCM')->firstOrFail();
        $filer = $this->seeded('ocm.admin');
        $other = $this->seeded('ocm.admin2');

        $document = $this->registerDocument($ocm, $filer);

        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Received,
            actor: $other,
            expectedMovementId: $document->openMovement?->id,
        );

        $this->assertFalse($filer->can('act', [$document->refresh(), MovementAction::Completed]));
        $this->assertTrue($other->can('act', [$document->refresh(), MovementAction::Completed]));
    }

    public function test_a_second_run_creates_nothing_and_never_rewrites_a_password(): void
    {
        $this->seed(OfficeAccountSeeder::class);

        $before = User::query()->count();
        $hash = $this->seeded('ocm.admin')->password;

        $this->seed(OfficeAccountSeeder::class);

        $this->assertSame($before, User::query()->count());

        // The whole reason the addresses are derived from the office code: a
        // re-run that rotated passwords would lock out every office that had
        // already been handed its credentials.
        $this->assertSame($hash, $this->seeded('ocm.admin')->password);
    }

    /**
     * The deployed database was seeded before the second Admin existed. The
     * upgrade is running the seeder again, and it must add the `.admin2`
     * accounts without touching the ones offices are already signing in with.
     */
    public function test_a_rerun_on_an_older_install_adds_only_the_second_admins(): void
    {
        $this->seed(OfficeAccountSeeder::class);

        // Back to the pre-2026-09-24 shape: the pair, and no second Admin.
        User::query()->where('email', 'like', '%.admin2@%')->delete();
        $before = User::query()->count();
        $hash = $this->seeded('ocm.admin')->password;

        config()->set('cicto.office_accounts.password', 'Second-Admin-2026');

        $this->seed(OfficeAccountSeeder::class);

        $offices = Office::query()->active()->count();

        $this->assertSame($before + $offices, User::query()->count());
        $this->assertSame($hash, $this->seeded('ocm.admin')->password);
        $this->assertTrue(Hash::check('Second-Admin-2026', $this->seeded('ocm.admin2')->password));
    }

    public function test_it_staffs_an_office_added_after_the_first_run(): void
    {
        $this->seed(OfficeAccountSeeder::class);

        $latecomer = Office::factory()->create(['code' => 'NEWCO', 'name' => 'New City Office']);

        $this->seed(OfficeAccountSeeder::class);

        $this->assertSame(3, User::query()->where('office_id', $latecomer->id)->count());
        $this->assertNotNull(User::query()->where('email', 'newco.admin@baliwag.gov.ph')->first());
        $this->assertNotNull(User::query()->where('email', 'newco.admin2@baliwag.gov.ph')->first());
    }

    public function test_it_writes_a_credential_sheet_for_everything_it_created(): void
    {
        $this->seed(OfficeAccountSeeder::class);

        $sheets = array_values(array_filter(
            Storage::disk('local')->files(),
            static fn (string $file): bool => str_starts_with($file, 'office-accounts-'),
        ));

        $this->assertCount(1, $sheets);

        $csv = (string) Storage::disk('local')->get($sheets[0]);
        $lines = explode("\n", trim($csv));

        // One header plus one line per account, or somebody is handed a
        // password sheet with an office missing from it.
        $this->assertCount(User::query()->count() + 1, $lines);
        $this->assertStringContainsString('"ocm.admin@baliwag.gov.ph"', $csv);
    }

    public function test_it_refuses_to_run_while_the_placeholder_offices_are_still_active(): void
    {
        // The state both the dev and the deployed database were left in before
        // the client's real office list arrived.
        Office::factory()->create(['code' => 'MPDO', 'name' => 'Planning Office']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MPDO');

        try {
            $this->seed(OfficeAccountSeeder::class);
        } finally {
            $this->assertSame(0, User::query()->count());
        }
    }

    public function test_every_account_shares_the_one_configured_password(): void
    {
        $this->seed(OfficeAccountSeeder::class);

        $shared = (string) config('cicto.office_accounts.password');

        // Asserted account by account rather than on a sample: a rollout where
        // 103 slips work and one does not is indistinguishable, from the
        // counter, from a broken account.
        foreach (User::query()->get() as $account) {
            $this->assertTrue(
                Hash::check($shared, $account->password),
                "{$account->email} does not open with the shared password.",
            );
        }
    }

    public function test_a_configured_password_replaces_the_default(): void
    {
        config()->set('cicto.office_accounts.password', 'Rollout-2026-Baliwag');

        $this->seed(OfficeAccountSeeder::class);

        $admin = $this->seeded('ocm.admin');

        $this->assertTrue(Hash::check('Rollout-2026-Baliwag', $admin->password));
        $this->assertFalse(Hash::check('password', $admin->password));
    }

    public function test_it_refuses_a_blank_password(): void
    {
        // An env line with nothing after the `=` would otherwise hash the empty
        // string into every account on the deployment.
        config()->set('cicto.office_accounts.password', '   ');

        $this->expectException(RuntimeException::class);

        try {
            $this->seed(OfficeAccountSeeder::class);
        } finally {
            $this->assertSame(0, User::query()->count());
        }
    }

    public function test_a_password_from_the_credential_sheet_signs_the_account_in(): void
    {
        $this->seed(OfficeAccountSeeder::class);

        // The sheet IS the deliverable -- it is what gets handed across a
        // counter -- so the thing worth asserting is not that a row exists but
        // that the string in it opens the account it names.
        $email = 'ocm.admin@baliwag.gov.ph';

        $this->post(route('login'), [
            'email' => $email,
            'password' => $this->passwordFromSheet($email),
        ])->assertRedirect();

        $this->assertAuthenticatedAs($this->seeded('ocm.admin'));
    }

    private function passwordFromSheet(string $email): string
    {
        $sheet = collect(Storage::disk('local')->files())
            ->first(static fn (string $file): bool => str_starts_with($file, 'office-accounts-'));

        $csv = trim((string) Storage::disk('local')->get((string) $sheet));

        foreach (explode("\n", $csv) as $line) {
            $row = str_getcsv($line, ',', '"', '\\');

            if (($row[4] ?? null) === $email) {
                return (string) $row[5];
            }
        }

        $this->fail("The credential sheet has no line for {$email}.");
    }

    /**
     * The CICTO office's own Gmail (client, 2026-09-28): the one account with
     * a real inbox, so the emailed sign-in code can be tested on the live site.
     */
    public function test_the_cicto_admin_is_created_on_the_offices_real_inbox(): void
    {
        $this->seed(OfficeAccountSeeder::class);

        $cicto = Office::query()->where('code', 'CICTO')->firstOrFail();
        $account = User::query()->where('email', 'cictobaliwagcity@gmail.com')->firstOrFail();

        $this->assertSame($cicto->id, $account->office_id);
        $this->assertSame(Role::Admin, $account->role);
        $this->assertSame('CICTO Admin', $account->name);
        $this->assertTrue($account->hasVerifiedEmail());
        $this->assertTrue(Hash::check((string) config('cicto.office_accounts.password'), $account->password));

        // Instead of the placeholder, not beside it: still three accounts.
        $this->assertFalse(User::query()->where('email', 'cicto.admin@baliwag.gov.ph')->exists());
        $this->assertSame(3, User::query()->where('office_id', $cicto->id)->count());
        $this->assertNotNull($this->seeded('cicto.admin2'));

        $this->assertStringContainsString('"cictobaliwagcity@gmail.com"', $this->sheet());
    }

    /**
     * The live site was seeded before the address was given: the existing
     * CICTO Admin is moved onto it -- same account, password and history --
     * rather than given a twin.
     */
    public function test_an_existing_cicto_admin_is_moved_onto_the_real_inbox(): void
    {
        $this->seed(OfficeAccountSeeder::class);

        // Back to how an install seeded before 2026-09-28 looks.
        $account = User::query()->where('email', 'cictobaliwagcity@gmail.com')->firstOrFail();
        $account->forceFill(['email' => 'cicto.admin@baliwag.gov.ph', 'password' => 'Their-Own-Password'])->save();
        $hash = $account->refresh()->password;
        $before = User::query()->count();

        $this->seed(OfficeAccountSeeder::class);

        $moved = User::query()->where('email', 'cictobaliwagcity@gmail.com')->firstOrFail();

        $this->assertSame($account->id, $moved->id, 'The same account, not a new one.');
        $this->assertSame($hash, $moved->password, 'Its password is left alone.');
        $this->assertTrue($moved->hasVerifiedEmail());
        $this->assertSame($before, User::query()->count());
        $this->assertFalse(User::query()->where('email', 'cicto.admin@baliwag.gov.ph')->exists());
        $this->assertTrue(SecurityEvent::query()
            ->where('type', SecurityEventType::EmailChangedByAdmin->value)
            ->where('summary', 'like', '%cicto.admin@baliwag.gov.ph to its real inbox, cictobaliwagcity@gmail.com%')
            ->exists());

        // And a third run has nothing left to do.
        $this->seed(OfficeAccountSeeder::class);
        $this->assertSame($before, User::query()->count());
        $this->assertSame($hash, $moved->refresh()->password);
    }

    /**
     * "Everything use real email" (2026-09-28): with CICTO_OFFICE_ACCOUNT_INBOX
     * set, every office account is an alias of that one inbox, so every
     * sign-in code arrives somewhere real.
     */
    public function test_with_an_inbox_every_account_is_created_on_an_alias_of_it(): void
    {
        config()->set('cicto.office_accounts.inbox', 'CictoBaliwagCity@gmail.com');

        $this->seed(OfficeAccountSeeder::class);

        $emails = User::query()->pluck('email');

        $this->assertSame(Office::query()->active()->count() * 3, $emails->count());
        $this->assertFalse($emails->contains(fn (string $email) => str_ends_with($email, '@baliwag.gov.ph')));
        $this->assertTrue($emails->every(fn (string $email) => (bool) preg_match(
            '/^cictobaliwagcity(\+[a-z0-9-]+\.(admin|admin2|clerk))?@gmail\.com$/',
            $email,
        )), 'Every address is the inbox or a "+" alias of it.');

        $this->assertSame('OCM Admin', User::query()->where('email', 'cictobaliwagcity+ocm.admin@gmail.com')->value('name'));
        $this->assertSame('OCM-TF Clerk', User::query()->where('email', 'cictobaliwagcity+ocm-tf.clerk@gmail.com')->value('name'));
        $this->assertSame('CICTO Admin', User::query()->where('email', 'cictobaliwagcity@gmail.com')->value('name'));
    }

    public function test_an_inbox_set_later_moves_every_account_still_on_its_login_name(): void
    {
        $this->seed(OfficeAccountSeeder::class);

        $ocm = $this->seeded('ocm.admin');
        $before = User::query()->count();

        config()->set('cicto.office_accounts.inbox', 'cictobaliwagcity@gmail.com');
        $this->seed(OfficeAccountSeeder::class);

        $moved = User::query()->findOrFail($ocm->id);

        $this->assertSame('cictobaliwagcity+ocm.admin@gmail.com', $moved->email, 'The same account, moved.');
        $this->assertSame($ocm->password, $moved->password, 'Its password is left alone.');
        $this->assertTrue($moved->hasVerifiedEmail());
        $this->assertSame($before, User::query()->count());
        $this->assertSame(0, User::query()->where('email', 'like', '%@baliwag.gov.ph')->count());
        $this->assertSame(
            $before - 1, // the CICTO Admin was on the real inbox from the start
            SecurityEvent::query()->where('type', SecurityEventType::EmailChangedByAdmin->value)->count(),
        );
    }

    /**
     * A person who has moved their account to their own inbox is not given a
     * twin by the next run, although "their" address is free again.
     */
    public function test_an_account_moved_by_its_owner_is_not_recreated(): void
    {
        config()->set('cicto.office_accounts.inbox', 'cictobaliwagcity@gmail.com');
        $this->seed(OfficeAccountSeeder::class);
        $before = User::query()->count();

        User::query()->where('email', 'cictobaliwagcity+ocm.admin@gmail.com')->update(['email' => 'juan.delacruz@gmail.com']);
        User::query()->where('email', 'cictobaliwagcity+trea.clerk@gmail.com')->update(['email' => 'maria@gmail.com', 'is_active' => false]);

        $this->seed(OfficeAccountSeeder::class);

        $this->assertSame($before, User::query()->count());
        $this->assertFalse(User::query()->where('email', 'cictobaliwagcity+ocm.admin@gmail.com')->exists());
        $this->assertFalse(User::query()->where('email', 'cictobaliwagcity+trea.clerk@gmail.com')->exists(), 'Nor one that was retired.');
    }

    public function test_an_inbox_must_be_one_plain_address(): void
    {
        foreach (['not-an-address', 'cictobaliwagcity+x@gmail.com'] as $inbox) {
            config()->set('cicto.office_accounts.inbox', $inbox);

            try {
                $this->seed(OfficeAccountSeeder::class);
                $this->fail("{$inbox} was accepted.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('CICTO_OFFICE_ACCOUNT_INBOX', $e->getMessage());
            }
        }

        $this->assertSame(0, User::query()->count());
    }

    private function sheet(): string
    {
        $files = Storage::disk('local')->files();

        $this->assertNotEmpty($files, 'No credential sheet was written.');

        return (string) Storage::disk('local')->get($files[0]);
    }

    private function seeded(string $localPart): User
    {
        return User::query()->where('email', $localPart.'@baliwag.gov.ph')->firstOrFail();
    }
}
