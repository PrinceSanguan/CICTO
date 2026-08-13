<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Office;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\OfficeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The practice accounts and sample documents used for client testing.
 *
 * The interesting assertions here are the ones about getting rid of them again.
 * These five logins share a password printed in the client's documentation, so
 * the command that creates them is only defensible if the command that removes
 * them genuinely works.
 */
class DemoDataCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([OfficeSeeder::class, DocumentTypeSeeder::class]);
    }

    public function test_it_creates_the_five_accounts_the_checklist_names(): void
    {
        $this->artisan('cicto:demo-data')->assertSuccessful();

        foreach ([
            'super@cicto.test',
            'admin@cicto.test',
            'mto@cicto.test',
            'sb@cicto.test',
            'clerk@cicto.test',
        ] as $email) {
            $user = User::query()->where('email', $email)->first();

            $this->assertNotNull($user, "{$email} was not created.");
            $this->assertTrue($user->is_active);
            $this->assertNotNull($user->email_verified_at, 'Unverified accounts cannot sign in.');
            $this->assertTrue(
                Hash::check('password', $user->password),
                "{$email} does not accept the password the checklist prints.",
            );
        }
    }

    /**
     * The checklist asks the client to confirm the Mayor's Office cannot see
     * SB-2026-00001. Without that document the most important test on the sheet
     * cannot be run at all.
     */
    public function test_it_creates_the_document_the_isolation_test_depends_on(): void
    {
        $this->artisan('cicto:demo-data')->assertSuccessful();

        $secret = Document::query()->where('title', 'Other office secret')->first();

        $this->assertNotNull($secret);
        $this->assertSame('SB-2026-00001', $secret->control_number);

        $mo = User::query()->where('email', 'admin@cicto.test')->firstOrFail();
        $sb = User::query()->where('email', 'sb@cicto.test')->firstOrFail();

        $this->assertFalse(
            Document::query()->visibleTo($mo)->whereKey($secret->id)->exists(),
            'The Mayor\'s Office can see the Sangguniang Bayan document.',
        );

        $this->assertTrue(
            Document::query()->visibleTo($sb)->whereKey($secret->id)->exists(),
        );
    }

    public function test_running_it_twice_does_not_duplicate_anything(): void
    {
        $this->artisan('cicto:demo-data')->assertSuccessful();

        $users = User::query()->count();
        $documents = Document::query()->count();

        $this->artisan('cicto:demo-data')->assertSuccessful();

        $this->assertSame($users, User::query()->count());
        $this->assertSame($documents, Document::query()->count());
    }

    /**
     * Document uses SoftDeletes, so a plain delete() leaves the row in place --
     * and documents.created_by_id is RESTRICT, so removing the account then
     * fails on a foreign key pointing at a document the model layer reports as
     * gone. The removal has to force-delete, and this asserts against the raw
     * tables rather than through the models for exactly that reason.
     */
    public function test_remove_deletes_everything_including_soft_deleted_rows(): void
    {
        $this->artisan('cicto:demo-data')->assertSuccessful();

        // Archive one first, so a soft-deleted row is in the way.
        Document::query()->firstOrFail()->delete();

        $this->artisan('cicto:demo-data', ['--remove' => true])->assertSuccessful();

        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('documents')->count());
        $this->assertSame(0, DB::table('document_movements')->count());
        $this->assertSame(0, DB::table('document_files')->count());

        // Reference data is not the command's to delete.
        $this->assertGreaterThan(0, DB::table('offices')->count());
        $this->assertGreaterThan(0, DB::table('document_types')->count());
    }

    public function test_remove_is_safe_to_run_when_there_is_nothing_to_remove(): void
    {
        $this->artisan('cicto:demo-data', ['--remove' => true])->assertSuccessful();

        $this->assertSame(0, User::query()->count());
    }

    /**
     * The whole point of the command is putting a publicly documented password
     * onto a server, so on a production host it must not be one keystroke away.
     */
    public function test_it_refuses_to_run_in_production_without_force(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $this->artisan('cicto:demo-data')->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_force_lets_it_run_in_production(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $this->artisan('cicto:demo-data', ['--force' => true])->assertSuccessful();

        $this->assertSame(5, User::query()->count());
    }

    /**
     * The ordinary seeder must never create these. A deploy hook that runs
     * db:seed on every release would otherwise put the weak password back
     * after somebody had removed it.
     */
    public function test_the_ordinary_seeder_never_creates_them_in_production(): void
    {
        app()->detectEnvironment(fn () => 'production');

        // The seeder object directly, not $this->seed(): db:seed asks for
        // confirmation in production, and the prompt is not what is under test
        // here -- the guard inside DatabaseSeeder::run() is.
        $this->app->make(DatabaseSeeder::class)->run();

        $this->assertSame(0, User::query()->count());
        $this->assertGreaterThan(0, Office::query()->count());
    }
}
