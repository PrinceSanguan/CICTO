<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Reference data -- safe to run in production.
        $this->call([
            OfficeSeeder::class,
            DocumentTypeSeeder::class,
        ]);

        if (app()->isProduction()) {
            $this->seedAnEmptyProductionDatabase();

            return;
        }

        $this->seedDemoAccounts();
    }

    /**
     * The accounts a live site needs to be usable, when it has none at all --
     * after `migrate:fresh --seed`, or on a first install.
     *
     * Only then. On every other deploy the database already has people in it,
     * and minting accounts stays the deliberate, by-name OfficeAccountSeeder
     * run it always was. What an empty database gets:
     *
     *  - ONE Super Admin, from CICTO_SUPER_ADMIN_EMAIL / _PASSWORD / _NAME. A
     *    real inbox, since the sign-in code is emailed; a password that passes
     *    the production rules. Without both, none is made -- and the console
     *    says how to make one.
     *  - Every office's three accounts (OfficeAccountSeeder), the CICTO Admin
     *    on the office's real Gmail. Not while the shared password is still the
     *    shipped "password": 156 accounts behind it on a live site is worse
     *    than none.
     */
    private function seedAnEmptyProductionDatabase(): void
    {
        if (User::query()->exists()) {
            return;
        }

        $this->seedSuperAdmin();

        if ((string) config('cicto.office_accounts.password') === 'password') {
            $this->command->warn('  Office accounts were NOT created: CICTO_OFFICE_ACCOUNT_PASSWORD is still "password". '
                .'Set a real one, then run: php artisan db:seed --class="Database\\Seeders\\OfficeAccountSeeder" --force',
            );

            return;
        }

        $this->call(OfficeAccountSeeder::class);
    }

    private function seedSuperAdmin(): void
    {
        $email = mb_strtolower(trim((string) config('cicto.super_admin.email')));
        $password = (string) config('cicto.super_admin.password');

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            ['email' => ['required', 'email', 'max:255'], 'password' => ['required', Password::defaults()]],
        );

        if ($validator->fails()) {
            $this->command->warn('  No Super Admin was created: '.$validator->errors()->first()
                .' Set CICTO_SUPER_ADMIN_EMAIL and CICTO_SUPER_ADMIN_PASSWORD, or create one with: '
                .'php artisan cicto:user <email> --name="Super Admin" --role=super_admin',
            );

            return;
        }

        (new User)->forceFill([
            'name' => (string) config('cicto.super_admin.name') ?: 'Super Admin',
            'email' => $email,
            'password' => $password,
            'role' => Role::SuperAdmin->value,
            'office_id' => null,
            'is_active' => true,
            // The address came from whoever set up the server, which is a
            // stronger assurance than a click-through -- as with cicto:user.
            'email_verified_at' => now(),
        ])->save();

        $this->command->line("  Super Admin created: {$email}. Its sign-in codes go to that inbox.");
    }

    /**
     * Local demo accounts, one per role.
     *
     * Created email_verified: MAIL_MAILER is `log`, so no verification message
     * can actually be delivered, and the `verified` middleware would otherwise
     * lock every one of these accounts out of the app on first login.
     */
    private function seedDemoAccounts(): void
    {
        // Named after the office they are actually in. These two used to read
        // "MPDO Admin" and "MPDO Clerk" while sitting in the Mayor's Office, so
        // every screen showed a name from one department beside documents from
        // another -- which reads as a bug in the office scoping when it is only
        // a label.
        //
        // OCM and TREA are the client's real codes for the Mayor's Office and
        // the Treasurer (DTS-Questions.docx, 2026-08-18). firstOrFail rather
        // than first: a missing office used to silently produce an Admin with
        // no office_id, which DocumentBuilder::visibleTo scopes very
        // differently, and nothing said so.
        $ocm = Office::query()->where('code', 'OCM')->firstOrFail();
        $trea = Office::query()->where('code', 'TREA')->firstOrFail();

        $accounts = [
            ['Super Admin', 'super@cicto.test', Role::SuperAdmin, null, 'System Administrator'],
            ['OCM Admin', 'admin@cicto.test', Role::Admin, $ocm, 'Department Head'],
            ['TREA Admin', 'mto@cicto.test', Role::Admin, $trea, 'Treasurer'],
            ['OCM Clerk', 'clerk@cicto.test', Role::User, $ocm, 'Administrative Aide'],
        ];

        foreach ($accounts as [$name, $email, $role, $office, $position]) {
            $user = User::query()->firstOrNew(['email' => $email]);

            $user->forceFill([
                'name' => $name,
                'email' => $email,
                'password' => 'password',
                'email_verified_at' => now(),
                'role' => $role,
                'office_id' => $office?->id,
                'position' => $position,
                'is_active' => true,
            ])->save();
        }
    }
}
