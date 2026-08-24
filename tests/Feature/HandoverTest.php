<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The deployment runbook is a list of commands somebody runs at 21:00 on a
 * server they have never seen. A runbook that names a command which does not
 * exist is worse than no runbook, so this asserts every one it mentions is
 * registered -- and that the two written for it actually work.
 */
class HandoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_command_named_in_the_runbook_exists(): void
    {
        $runbook = (string) file_get_contents(base_path('docs/handover/DEPLOYMENT.md'));

        preg_match_all('/php artisan ([a-z0-9:_-]+)/', $runbook, $matches);

        $named = array_values(array_unique($matches[1]));
        $registered = array_keys(Artisan::all());

        $this->assertNotEmpty($named, 'The runbook names no commands at all.');

        foreach ($named as $command) {
            $this->assertContains(
                $command,
                $registered,
                "docs/handover/DEPLOYMENT.md tells the operator to run `php artisan {$command}`, which does not exist.",
            );
        }
    }

    /**
     * The phase-3 restore runbook is the procedure the app itself points at --
     * SystemController names the file and the Super Admin UI tells the operator
     * to follow it. It told them to run `documents:verify-status`, which has
     * never existed, and to unzip an archive the code did not produce.
     */
    public function test_every_command_in_the_restore_runbook_exists(): void
    {
        $runbook = (string) file_get_contents(
            base_path('docs/implementation/phase-3-trust-and-toolchain.md'),
        );

        preg_match_all('/php artisan ([a-z0-9:_-]+)/', $runbook, $matches);

        $registered = array_keys(Artisan::all());

        foreach (array_unique($matches[1]) as $command) {
            $this->assertContains(
                $command,
                $registered,
                "The restore runbook tells the operator to run `php artisan {$command}`, which does not exist.",
            );
        }
    }

    public function test_every_seeder_named_in_the_runbook_exists(): void
    {
        $runbook = (string) file_get_contents(base_path('docs/handover/DEPLOYMENT.md'));

        preg_match_all('/--class=([A-Za-z]+)/', $runbook, $matches);

        foreach (array_unique($matches[1]) as $seeder) {
            $this->assertTrue(
                class_exists("Database\\Seeders\\{$seeder}"),
                "The runbook names seeder {$seeder}, which does not exist.",
            );
        }
    }

    public function test_the_host_check_reports_without_throwing(): void
    {
        // It has to survive a host where things are MISSING -- that is the
        // whole point of running it.
        $this->artisan('cicto:host-check')->assertSuccessful();
    }

    /**
     * A local backups disk is as fatal on a container host as a local documents
     * disk, and for a while only the second one was named. A deployed
     * environment ran with "Disk: backups | OK local" printed beside archives
     * that every deploy destroyed.
     */
    public function test_the_host_check_names_both_ephemeral_disks(): void
    {
        config()->set('filesystems.disks.documents.driver', 'local');
        config()->set('filesystems.disks.backups.driver', 'local');

        $this->artisan('cicto:host-check')
            ->expectsOutputToContain('Documents durable?')
            ->expectsOutputToContain('Backups durable?')
            ->assertSuccessful();
    }

    public function test_the_super_admin_command_creates_a_verified_active_super_admin(): void
    {
        $this->artisan('cicto:create-super-admin', [
            '--name' => 'Real Super Admin',
            '--email' => 'super@baliwag.example',
        ])
            ->expectsQuestion('Password', 'Corr3ct-Horse-Batt3ry')
            ->expectsQuestion('Confirm password', 'Corr3ct-Horse-Batt3ry')
            ->assertSuccessful();

        $user = User::firstWhere('email', 'super@baliwag.example');

        $this->assertNotNull($user);
        $this->assertSame(Role::SuperAdmin, $user->role);
        $this->assertTrue($user->is_active);

        // Verified on creation: nobody should be locked out of a brand new
        // deployment waiting on an email service that may not exist yet.
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_the_super_admin_command_refuses_a_mismatched_password(): void
    {
        $this->artisan('cicto:create-super-admin', [
            '--name' => 'Real Super Admin',
            '--email' => 'super@baliwag.example',
        ])
            ->expectsQuestion('Password', 'Corr3ct-Horse-Batt3ry')
            ->expectsQuestion('Confirm password', 'something-else')
            ->assertFailed();

        $this->assertNull(User::firstWhere('email', 'super@baliwag.example'));
    }

    public function test_the_super_admin_command_refuses_a_weak_password(): void
    {
        $this->artisan('cicto:create-super-admin', [
            '--name' => 'Real Super Admin',
            '--email' => 'super@baliwag.example',
        ])
            ->expectsQuestion('Password', 'password')
            ->expectsQuestion('Confirm password', 'password')
            ->assertFailed();

        $this->assertNull(User::firstWhere('email', 'super@baliwag.example'));
    }
}
