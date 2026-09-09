<?php

namespace Tests\Feature;

use App\Actions\Documents\TransitionDocument;
use App\Enums\MovementAction;
use App\Enums\Role;
use App\Models\Office;
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

    public function test_every_active_office_gets_one_admin_and_one_clerk(): void
    {
        $this->seed(OfficeAccountSeeder::class);

        $offices = Office::query()->active()->get();

        // Guards against a seeder that "passes" by finding no offices at all.
        $this->assertGreaterThan(40, $offices->count());
        $this->assertSame($offices->count() * 2, User::query()->count());

        foreach ($offices as $office) {
            $accounts = User::query()->where('office_id', $office->id)->get();

            $this->assertCount(2, $accounts, "{$office->code} did not get a pair.");

            $this->assertSame(
                1,
                $accounts->where('role', Role::Admin)->count(),
                "{$office->code} has no single Admin, so nothing forwarded there can be opened.",
            );
            $this->assertSame(1, $accounts->where('role', Role::User)->count());

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

        $this->assertNotEmpty($offered);

        foreach ($offered as $office) {
            $this->assertSame(
                1,
                User::query()
                    ->where('office_id', $office['id'])
                    ->where('role', Role::Admin)
                    ->where('is_active', true)
                    ->count(),
                "{$office['code']} is offered by the submit form with no active Admin behind it.",
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

    public function test_it_staffs_an_office_added_after_the_first_run(): void
    {
        $this->seed(OfficeAccountSeeder::class);

        $latecomer = Office::factory()->create(['code' => 'NEWCO', 'name' => 'New City Office']);

        $this->seed(OfficeAccountSeeder::class);

        $this->assertSame(2, User::query()->where('office_id', $latecomer->id)->count());
        $this->assertNotNull(User::query()->where('email', 'newco.admin@baliwag.gov.ph')->first());
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

    private function seeded(string $localPart): User
    {
        return User::query()->where('email', $localPart.'@baliwag.gov.ph')->firstOrFail();
    }
}
