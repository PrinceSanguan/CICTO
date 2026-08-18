<?php

namespace App\Console\Commands;

use App\Actions\Documents\RegisterDocument;
use App\Enums\DocumentPriority;
use App\Enums\Role;
use App\Enums\SecurityEventType;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Office;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Creates the practice accounts and sample documents the client's testing
 * checklist refers to.
 *
 * Deliberately NOT part of `db:seed`. DatabaseSeeder refuses to create these in
 * production, and that guard stays: a deploy hook that quietly recreates
 * accounts with a known password every time it runs is how a weak login
 * outlives the testing it was made for. This is a separate command, run on
 * purpose, that says what it did and can undo it.
 *
 * The password is the word "password" because the printed checklist tells the
 * client to type exactly that. Nothing about that is safe, which is why the
 * command refuses to run in production without --force, prints a warning, and
 * ships with --remove.
 */
class DemoDataCommand extends Command
{
    protected $signature = 'cicto:demo-data
                            {--remove : Delete the practice accounts and their documents}
                            {--force : Required in production, because the password is public}';

    protected $description = 'Create (or remove) the practice accounts and documents used for client testing';

    /** Everything this command creates carries one of these addresses. */
    private const PASSWORD = 'password';

    /**
     * @var list<array{name: string, email: string, role: Role, office: ?string, position: string}>
     */
    private const ACCOUNTS = [
        ['name' => 'Super Admin', 'email' => 'super@cicto.test', 'role' => Role::SuperAdmin, 'office' => null, 'position' => 'System Administrator'],
        ['name' => 'OCM Admin', 'email' => 'admin@cicto.test', 'role' => Role::Admin, 'office' => 'OCM', 'position' => 'Department Head'],
        ['name' => 'TREA Admin', 'email' => 'mto@cicto.test', 'role' => Role::Admin, 'office' => 'TREA', 'position' => 'Treasurer'],
        ['name' => 'SP Admin', 'email' => 'sb@cicto.test', 'role' => Role::Admin, 'office' => 'SP', 'position' => 'Board Secretary'],
        ['name' => 'OCM Clerk', 'email' => 'clerk@cicto.test', 'role' => Role::User, 'office' => 'OCM', 'position' => 'Administrative Aide'],
    ];

    /**
     * Sample documents.
     *
     * The Sangguniang Panlungsod one is load-bearing: the checklist asks the
     * client to confirm the Mayor's Office cannot see it, which is the single
     * most important thing they can verify. Without it that test cannot be run.
     *
     * The `type` codes are the client's own, from DocumentTypeSeeder. They used
     * to be absent, and every sample document then got whichever type had the
     * lowest id -- nine folders all classified the same way, which makes the
     * "filter by document type" step on the checklist prove nothing. A missing
     * code falls back to the first active type rather than failing the run.
     *
     * @var list<array{title: string, office: string, by: string, type: string, priority: DocumentPriority}>
     */
    private const DOCUMENTS = [
        ['title' => 'Barangay drainage clearance', 'office' => 'OCM', 'by' => 'clerk@cicto.test', 'type' => 'MAYORS-CLEARANCE', 'priority' => DocumentPriority::Normal],
        ['title' => 'Purchase order for laptops', 'office' => 'OCM', 'by' => 'clerk@cicto.test', 'type' => 'PO', 'priority' => DocumentPriority::High],
        ['title' => 'Payroll adjustment request', 'office' => 'OCM', 'by' => 'clerk@cicto.test', 'type' => 'PAYROLL', 'priority' => DocumentPriority::Normal],
        ['title' => 'Fire safety inspection', 'office' => 'OCM', 'by' => 'clerk@cicto.test', 'type' => 'REQUEST', 'priority' => DocumentPriority::Urgent],
        ['title' => 'Scholarship endorsement', 'office' => 'OCM', 'by' => 'clerk@cicto.test', 'type' => 'ENDORSEMENT', 'priority' => DocumentPriority::Normal],
        ['title' => 'Water line repair', 'office' => 'OCM', 'by' => 'clerk@cicto.test', 'type' => 'REQUEST', 'priority' => DocumentPriority::High],
        ['title' => 'Business permit renewal', 'office' => 'TREA', 'by' => 'mto@cicto.test', 'type' => 'BUSINESS-PERMIT', 'priority' => DocumentPriority::Normal],
        ['title' => 'Statement of receipts', 'office' => 'TREA', 'by' => 'mto@cicto.test', 'type' => 'CERTIFICATION', 'priority' => DocumentPriority::Normal],
        ['title' => 'Other office secret', 'office' => 'SP', 'by' => 'sb@cicto.test', 'type' => 'CONFIDENTIAL', 'priority' => DocumentPriority::Normal],
    ];

    public function handle(RegisterDocument $register): int
    {
        if ($this->option('remove')) {
            return $this->remove();
        }

        /*
         * The --force gate mirrors Laravel's own convention for commands that
         * are dangerous in production. It is not bureaucracy: the whole point
         * of this command is to put a publicly documented password onto a
         * server, and that should never be one keystroke away by accident.
         */
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('This creates accounts whose password is printed in the client documentation.');
            $this->error('On a production host, re-run with --force if that is genuinely what you want.');

            return self::FAILURE;
        }

        if (Office::query()->count() === 0 || DocumentType::query()->count() === 0) {
            $this->error('No offices or document types found. Run `php artisan db:seed --force` first.');

            return self::FAILURE;
        }

        $accounts = $this->createAccounts();
        $documents = $this->createDocuments($register);

        SecurityEvent::log(
            SecurityEventType::UserCreated,
            sprintf('Practice accounts created for client testing (%d accounts).', count($accounts)),
            subjectLabel: 'cicto:demo-data',
        );

        $this->newLine();
        $this->info(sprintf(
            '%d practice accounts ready, %d sample documents created.',
            count($accounts),
            $documents,
        ));

        $this->newLine();
        $this->table(
            ['Email', 'Role', 'Office', 'Password'],
            array_map(fn (array $row) => [
                $row['email'],
                $row['role']->label(),
                $row['office'] ?? '—',
                self::PASSWORD,
            ], self::ACCOUNTS),
        );

        $this->newLine();
        $this->warn('  These five logins share the password "password" and it is printed in');
        $this->warn('  the client testing guide. Anybody who finds this site can sign in as');
        $this->warn('  Super Admin. Before the system holds a single real document, run:');
        $this->newLine();
        $this->line('      php artisan cicto:demo-data --remove');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * @return list<User>
     */
    private function createAccounts(): array
    {
        $created = [];

        foreach (self::ACCOUNTS as $account) {
            $office = $account['office'] === null
                ? null
                : Office::query()->where('code', $account['office'])->first();

            if ($account['office'] !== null && $office === null) {
                $this->warn("Skipped {$account['email']}: office {$account['office']} does not exist.");

                continue;
            }

            $user = User::query()->firstOrNew(['email' => $account['email']]);

            /*
             * forceFill because `role` and `office_id` are excluded from the
             * fillable whitelist by construction -- that exclusion is what
             * stops a request from granting privilege.
             *
             * Verified on creation: the `verified` middleware gates the whole
             * app and this deployment has no outgoing mail, so an unverified
             * practice account would be one nobody could sign in to.
             */
            $user->forceFill([
                'name' => $account['name'],
                'email' => $account['email'],
                'password' => Hash::make(self::PASSWORD),
                'email_verified_at' => now(),
                'role' => $account['role']->value,
                'office_id' => $office?->id,
                'position' => $account['position'],
                'is_active' => true,
            ])->save();

            $created[] = $user;
        }

        return $created;
    }

    private function createDocuments(RegisterDocument $register): int
    {
        $fallbackType = DocumentType::query()->where('is_active', true)->orderBy('id')->firstOrFail();
        $types = DocumentType::query()->where('is_active', true)->pluck('id', 'code');
        $made = 0;

        foreach (self::DOCUMENTS as $document) {
            $office = Office::query()->where('code', $document['office'])->first();
            $creator = User::query()->where('email', $document['by'])->first();

            // Say so. This used to `continue` in silence, so renaming an office
            // code in the seeder quietly produced a run that reported success
            // and created nothing -- including the isolation-test document the
            // client's checklist depends on.
            if ($office === null || $creator === null) {
                $this->warn(sprintf(
                    'Skipped "%s": %s does not exist.',
                    $document['title'],
                    $office === null ? "office {$document['office']}" : "account {$document['by']}",
                ));

                continue;
            }

            // Skip anything already there, so re-running does not pile up
            // duplicates every time somebody presses the button again.
            $exists = Document::query()
                ->where('title', $document['title'])
                ->where('originating_office_id', $office->id)
                ->exists();

            if ($exists) {
                continue;
            }

            $register->handle(
                title: $document['title'],
                documentTypeId: (int) ($types[$document['type']] ?? $fallbackType->id),
                priority: $document['priority'],
                originatingOffice: $office,
                creator: $creator,
                description: 'Practice document created for client testing.',
            );

            $made++;
        }

        return $made;
    }

    /**
     * Removes the accounts and everything they raised.
     *
     * Documents are matched by creator rather than by title, so anything the
     * client made while signed in as a practice account goes with it. That is
     * the intent: after this runs, no trace of the practice login should be
     * able to sign in or hold a record.
     */
    private function remove(): int
    {
        $emails = array_column(self::ACCOUNTS, 'email');

        $users = User::query()->whereIn('email', $emails)->get();

        if ($users->isEmpty()) {
            $this->info('No practice accounts found. Nothing to remove.');

            return self::SUCCESS;
        }

        /*
         * withTrashed and forceDelete, both load-bearing.
         *
         * Document uses SoftDeletes, so delete() only stamps deleted_at and
         * leaves the row in place -- and documents.created_by_id is RESTRICT,
         * so the account delete then fails on a foreign key pointing at a
         * document the model layer swears is gone. withTrashed also catches
         * anything already soft-deleted during testing, which would otherwise
         * be invisible here and block the removal just the same.
         */
        $documents = Document::withTrashed()
            ->whereIn('created_by_id', $users->pluck('id'))
            ->get();

        DB::transaction(function () use ($users, $documents) {
            foreach ($documents as $document) {
                $document->signatures()->delete();
                $document->comments()->delete();
                $document->movements()->delete();
                $document->files()->delete();
                $document->forceDelete();
            }

            // Security events reference the account, so they go first or the
            // foreign key refuses the delete.
            SecurityEvent::query()->whereIn('user_id', $users->pluck('id'))->delete();

            foreach ($users as $user) {
                $user->delete();
            }
        });

        $this->info(sprintf(
            'Removed %d practice accounts and %d of their documents.',
            $users->count(),
            $documents->count(),
        ));

        return self::SUCCESS;
    }
}
