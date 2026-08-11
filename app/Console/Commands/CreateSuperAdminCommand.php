<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Creates the real Super Admin during deployment.
 *
 * A command rather than a seeder because the password must not be typed into a
 * file that gets committed, and must not be a known default. It is prompted for
 * and hidden, and the account is created verified so nobody is locked out
 * waiting on an email service that may not exist yet.
 */
class CreateSuperAdminCommand extends Command
{
    protected $signature = 'cicto:create-super-admin
                            {--name= : Full name, as it should read on an audit record}
                            {--email= : Sign-in address}';

    protected $description = 'Create the real Super Admin account';

    public function handle(): int
    {
        $name = (string) ($this->option('name') ?: $this->ask('Full name'));
        $email = (string) ($this->option('email') ?: $this->ask('Email address'));
        $password = (string) $this->secret('Password');
        $confirm = (string) $this->secret('Confirm password');

        if ($password !== $confirm) {
            $this->error('The passwords do not match.');

            return self::FAILURE;
        }

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', Password::min(12)->letters()->numbers()->uncompromised()],
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
        $this->line('Delete every demo account before go-live.');

        return self::SUCCESS;
    }
}
