<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

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
            return;
        }

        $this->seedDemoAccounts();
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
