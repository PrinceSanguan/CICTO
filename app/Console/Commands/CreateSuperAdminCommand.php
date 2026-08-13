<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Creates the real Super Admin during deployment.
 *
 * A command rather than a seeder because the password must not be typed into a
 * file that gets committed, and must not be a known default. The account is
 * created verified so nobody is locked out waiting on an email service that may
 * not exist yet.
 *
 * Runs with or without a terminal. Managed hosting panels execute artisan
 * through a web form with no TTY, where a hidden prompt reads back as an empty
 * string and the command fails for reasons nobody can see. With no terminal it
 * generates a strong password and prints it once instead.
 *
 * There is deliberately no --password option: on those same panels the command
 * line is stored in a history list, so a password passed that way outlives the
 * deployment.
 */
class CreateSuperAdminCommand extends Command
{
    protected $signature = 'cicto:create-super-admin
                            {--name= : Full name, as it should read on an audit record}
                            {--email= : Sign-in address}';

    protected $description = 'Create the real Super Admin account';

    public function handle(): int
    {
        $interactive = $this->input->isInteractive();

        $name = (string) ($this->option('name') ?: ($interactive ? $this->ask('Full name') : ''));
        $email = (string) ($this->option('email') ?: ($interactive ? $this->ask('Email address') : ''));

        if (! $interactive && ($name === '' || $email === '')) {
            $this->error('Without a terminal, --name and --email are both required.');

            return self::FAILURE;
        }

        $generated = ! $interactive;

        if ($generated) {
            // 24 characters from Str::password, which mixes letters, numbers
            // and symbols. Long enough that printing it once and changing it
            // after first sign-in is a reasonable trade.
            $password = Str::password(24);
        } else {
            $password = (string) $this->secret('Password');

            if ($password !== (string) $this->secret('Confirm password')) {
                $this->error('The passwords do not match.');

                return self::FAILURE;
            }
        }

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                /*
                 * No uncompromised() here, matching cicto:user and the reason
                 * given in AppServiceProvider: it calls the Have I Been Pwned
                 * API and FAILS OPEN when the host has no outbound HTTPS, which
                 * is common on municipal hosting. A check that silently passes
                 * everything reads as protection without being any.
                 */
                'password' => ['required', Password::min(12)->letters()->numbers()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        // role is deliberately not fillable -- it is not something a request can
        // ever set, so it is assigned explicitly here.
        $user->forceFill([
            'role' => Role::SuperAdmin->value,
            'is_active' => true,
            'email_verified_at' => now(),
        ])->save();

        $this->info("Super Admin created: {$user->email}");

        if ($generated) {
            $this->newLine();
            $this->line('  Password: '.$password);
            $this->newLine();
            $this->warn('  Sign in with this now and change it under Settings > Security.');
            $this->warn('  Then delete this command from your panel history: the password is in');
            $this->warn('  the output above and will stay there until you do.');
            $this->newLine();
        }

        $this->line('Delete every demo account before go-live.');

        return self::SUCCESS;
    }
}
