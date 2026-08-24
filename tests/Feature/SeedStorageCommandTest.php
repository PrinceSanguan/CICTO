<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentFile;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\OfficeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The command that gives the practice documents something to open.
 *
 * The assertion that carries the most weight here is the checksum one. This
 * command exists to put bytes on a bucket, and the tempting way to write it is
 * Storage::put() plus a hand-built document_files row -- which means inventing
 * the sha256 that §21 signature verification later re-computes. Writing that
 * field by hand produces files which look right in the UI and report as
 * tampered the first time `cicto:verify-signatures` runs. Going through
 * StoreDocumentFile is what keeps the two in agreement, so the test asserts the
 * agreement rather than the call.
 */
class SeedStorageCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([OfficeSeeder::class, DocumentTypeSeeder::class]);

        Storage::fake('documents');

        // cicto:demo-data creates documents with NO attachment -- that absence
        // is the condition this command exists to fix, so it is the fixture.
        $this->artisan('cicto:demo-data')->assertSuccessful();
    }

    public function test_it_attaches_a_readable_file_to_every_document_that_has_none(): void
    {
        $this->assertSame(0, DocumentFile::query()->count(), 'Fixture should start with no files.');
        $this->assertGreaterThan(0, Document::query()->count(), 'Fixture should have documents.');

        $this->artisan('cicto:seed-storage')->assertSuccessful();

        $files = DocumentFile::query()->get();

        $this->assertSame(
            Document::query()->count(),
            $files->count(),
            'Every document should have come away with exactly one file.',
        );

        foreach ($files as $file) {
            $this->assertSame('documents', $file->disk);
            $this->assertTrue(
                Storage::disk('documents')->exists($file->path),
                "{$file->path} is recorded but not on the disk.",
            );
            $this->assertSame(1, $file->version, 'A seeded file is the first version.');
        }
    }

    /**
     * The whole reason this goes through StoreDocumentFile rather than
     * Storage::put(). A stored checksum that does not describe the stored bytes
     * makes every signature bound to the file unverifiable.
     */
    public function test_the_stored_checksum_describes_the_stored_bytes(): void
    {
        $this->artisan('cicto:seed-storage')->assertSuccessful();

        foreach (DocumentFile::query()->get() as $file) {
            $bytes = Storage::disk('documents')->get($file->path);

            $this->assertSame(
                hash('sha256', (string) $bytes),
                $file->checksum_sha256,
                "The checksum on {$file->path} does not match what is on the disk.",
            );

            $this->assertStringStartsWith('%PDF-', (string) $bytes, 'A placeholder should be a real PDF.');
        }
    }

    /**
     * Re-running must not grow a new version of everything.
     *
     * This is the failure mode that kept it out of DatabaseSeeder: document
     * versions are immutable and append-only, so a command that re-attaches on
     * every run turns each deploy into another version of every document.
     */
    public function test_running_it_twice_does_not_create_a_second_version(): void
    {
        $this->artisan('cicto:seed-storage')->assertSuccessful();
        $afterFirst = DocumentFile::query()->count();

        $this->artisan('cicto:seed-storage')->assertSuccessful();

        $this->assertSame($afterFirst, DocumentFile::query()->count(), 'The second run attached files again.');
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->artisan('cicto:seed-storage --dry-run')->assertSuccessful();

        $this->assertSame(0, DocumentFile::query()->count());
        $this->assertEmpty(Storage::disk('documents')->allFiles());
    }

    public function test_limit_stops_after_the_given_number_of_documents(): void
    {
        $this->artisan('cicto:seed-storage --limit=2')->assertSuccessful();

        $this->assertSame(2, DocumentFile::query()->count());
    }

    /**
     * The same guard DemoDataCommand carries, for a different cost: this one
     * writes billable objects to a bucket rather than weak passwords to a
     * database, and either way an accidental production run should not be one
     * keystroke away.
     */
    public function test_it_refuses_to_run_in_production_without_force(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('cicto:seed-storage')->assertFailed();

        $this->assertSame(0, DocumentFile::query()->count());
    }

    /**
     * The preview must not need the flag that means "yes, write to production".
     *
     * It did, and the effect was the obvious one: the safe way to look and the
     * unsafe way to act were the same keystroke, so the operator went straight
     * to --force on a disk that had never been proved writable.
     */
    public function test_a_dry_run_does_not_need_force_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('cicto:seed-storage --dry-run')->assertSuccessful();

        $this->assertSame(0, DocumentFile::query()->count());
        $this->assertEmpty(Storage::disk('documents')->allFiles());
    }
}
