<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\DocumentType;
use App\Models\Office;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `php artisan migrate:fresh --seed --force` on the live site (2026-09-28):
 * refused unless CICTO_ALLOW_DATABASE_WIPE is set, and -- when it is run --
 * leaving a site somebody can sign in to.
 */
class ProductionSeedTest extends TestCase
{
    use RefreshDatabase;

    private const STRONG = 'Cicto-Baliwag-2026!';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        config([
            'cicto.scan_base_url' => 'https://cicto.site',
            'cicto.super_admin.email' => 'CICTOBaliwagCity+admin@gmail.com',
            'cicto.super_admin.password' => self::STRONG,
            'cicto.super_admin.name' => 'CICTO Super Admin',
            'cicto.office_accounts.password' => 'Office-Rollout-2026!',
        ]);
    }

    protected function tearDown(): void
    {
        // Static on the framework's commands: it would outlive this test.
        DB::prohibitDestructiveCommands(false);
        $this->app['env'] = 'testing';

        parent::tearDown();
    }

    private function inProduction(): void
    {
        $this->app['env'] = 'production';
    }

    /** db:seed as the live deploy runs it: --force, or production asks first. */
    private function seedLive(): void
    {
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();
    }

    private function prohibited(): bool
    {
        return (bool) (new \ReflectionProperty(FreshCommand::class, 'prohibitedFromRunning'))->getValue();
    }

    public function test_production_refuses_migrate_fresh_unless_the_wipe_is_allowed(): void
    {
        $this->inProduction();

        config(['cicto.allow_database_wipe' => false]);
        (new AppServiceProvider($this->app))->boot();
        $this->assertTrue($this->prohibited(), 'A live database must not be wiped by accident.');

        $this->artisan('migrate:fresh', ['--force' => true])
            ->expectsOutputToContain('prohibited')
            ->assertFailed();

        config(['cicto.allow_database_wipe' => true]);
        (new AppServiceProvider($this->app))->boot();
        $this->assertFalse($this->prohibited(), 'CICTO_ALLOW_DATABASE_WIPE=true lets it run.');
    }

    public function test_outside_production_nothing_is_prohibited(): void
    {
        config(['cicto.allow_database_wipe' => false]);
        (new AppServiceProvider($this->app))->boot();

        $this->assertFalse($this->prohibited());
    }

    public function test_an_empty_live_database_is_seeded_ready_to_sign_in(): void
    {
        $this->inProduction();

        $this->seedLive();

        $this->assertGreaterThan(40, Office::query()->active()->count());
        $this->assertSame(43, DocumentType::query()->active()->count());

        $super = User::query()->where('role', Role::SuperAdmin->value)->sole();
        $this->assertSame('cictobaliwagcity+admin@gmail.com', $super->email, 'Lower-cased.');
        $this->assertSame('CICTO Super Admin', $super->name);
        $this->assertNull($super->office_id);
        $this->assertTrue($super->hasVerifiedEmail());
        $this->assertTrue(Hash::check(self::STRONG, $super->password));

        // Every office's three accounts, the CICTO Admin on the real Gmail.
        $this->assertSame(Office::query()->active()->count() * 3 + 1, User::query()->count());
        $this->assertTrue(User::query()->where('email', 'cictobaliwagcity@gmail.com')->where('role', 'admin')->exists());

        // No demo accounts on a live site.
        $this->assertFalse(User::query()->where('email', 'like', '%@cicto.test')->exists());
    }

    public function test_a_live_database_with_accounts_is_left_alone(): void
    {
        $this->inProduction();
        $this->seedLive();
        $before = User::query()->count();

        config(['cicto.super_admin.email' => 'someone.else@gmail.com']);
        $this->seedLive();

        $this->assertSame($before, User::query()->count(), 'Every later deploy\'s db:seed adds nobody.');
    }

    public function test_no_super_admin_without_a_strong_password_and_no_office_accounts_on_the_default_one(): void
    {
        $this->inProduction();
        config([
            'cicto.super_admin.password' => 'password',
            'cicto.office_accounts.password' => 'password',
        ]);

        $this->artisan('db:seed', ['--force' => true])
            ->expectsOutputToContain('No Super Admin was created')
            ->expectsOutputToContain('Office accounts were NOT created')
            ->assertSuccessful();

        $this->assertSame(0, User::query()->count());
        $this->assertGreaterThan(40, Office::query()->count(), 'The reference data still goes in.');
    }

    public function test_no_super_admin_without_an_address(): void
    {
        $this->inProduction();
        config(['cicto.super_admin.email' => null]);

        $this->seedLive();

        $this->assertFalse(User::query()->where('role', Role::SuperAdmin->value)->exists());
        $this->assertTrue(User::query()->where('email', 'cictobaliwagcity@gmail.com')->exists(), 'The offices are staffed regardless.');
    }

    public function test_local_seeding_is_unchanged(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertTrue(User::query()->where('email', 'super@cicto.test')->exists());
        $this->assertFalse(User::query()->where('email', 'cictobaliwagcity@gmail.com')->exists(), 'Office accounts stay a by-name run locally.');
    }
}
