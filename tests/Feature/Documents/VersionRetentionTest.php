<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\RegisterDocument;
use App\Actions\Documents\SignDocument;
use App\Actions\Documents\StoreDocumentFile;
use App\Actions\Documents\TransitionDocument;
use App\Enums\DocumentPriority;
use App\Enums\MovementAction;
use App\Enums\SignatureMethod;
use App\Models\Document;
use App\Models\DocumentFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * #10 Version Control, and the retention pruner's interaction with §15.
 */
class VersionRetentionTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    /**
     * The window these tests exercise, pinned rather than inherited.
     *
     * cicto.retention.versions.after_days is the CLIENT'S number and it is
     * still moving -- 1095 today, 1825 if ARO comes back with five years. These
     * tests are about the pruner's rules (never the first version, never the
     * current one, never a signed one, never an open document), not about the
     * client's retention policy, and every one of them stops proving anything
     * the moment the window grows past the fixture's age. Pinning it here means
     * raising the shipped figure cannot silently turn this file green-and-empty.
     */
    private const WINDOW_DAYS = 180;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cicto.retention.versions.after_days' => self::WINDOW_DAYS]);
    }

    /** A completed document with the given file contents as successive versions. */
    private function completedWithVersions(array $contents): Document
    {
        Storage::fake('documents');

        $office = $this->office();
        $clerk = $this->staff($office);
        $admin = $this->admin($office);

        $document = app(RegisterDocument::class)->handle(
            title: 'Versioned request',
            documentTypeId: $this->documentType()->id,
            priority: DocumentPriority::Normal,
            originatingOffice: $office,
            creator: $clerk,
            upload: UploadedFile::fake()->createWithContent('v1.pdf', array_shift($contents)),
        );

        foreach ($contents as $i => $content) {
            app(StoreDocumentFile::class)->handle(
                $document,
                UploadedFile::fake()->createWithContent('v'.($i + 2).'.pdf', $content),
                $admin,
            );
        }

        foreach ([MovementAction::Received, MovementAction::Completed] as $action) {
            $document->refresh();
            app(TransitionDocument::class)->handle(
                document: $document,
                action: $action,
                actor: $admin,
                expectedMovementId: $document->openMovement?->id,
            );
        }

        $document->forceFill(['completed_at' => now()->subDays(self::WINDOW_DAYS * 2)])->save();

        return $document->refresh();
    }

    public function test_pruning_is_disabled_by_default_and_only_reports(): void
    {
        $document = $this->completedWithVersions(['a', 'b', 'c']);

        $this->artisan('cicto:prune-versions')->assertSuccessful();

        // Nothing is deleted on a developer's assumption -- client question B6.
        $this->assertSame(0, DocumentFile::query()->whereNotNull('purged_at')->count());
        $this->assertSame(3, DocumentFile::query()->where('document_id', $document->id)->count());
    }

    public function test_it_never_purges_the_first_or_current_version(): void
    {
        config(['cicto.retention.versions.enabled' => true]);

        $document = $this->completedWithVersions(['a', 'b', 'c', 'd']);

        $this->artisan('cicto:prune-versions --force')->assertSuccessful();

        $byVersion = DocumentFile::query()
            ->where('document_id', $document->id)
            ->get()
            ->keyBy('version');

        // v1 is the original submission and v4 is the current file. Those are
        // the two a records office actually gets asked for.
        $this->assertNull($byVersion[1]->purged_at);
        $this->assertNull($byVersion[4]->purged_at);
        $this->assertNotNull($byVersion[2]->purged_at);
        $this->assertNotNull($byVersion[3]->purged_at);

        // The rows survive, so the history never develops holes.
        $this->assertSame(4, $byVersion->count());
    }

    public function test_it_never_purges_a_version_that_was_signed(): void
    {
        config(['cicto.retention.versions.enabled' => true]);

        Storage::fake('documents');
        $office = $this->office();
        $clerk = $this->staff($office);
        $admin = $this->admin($office);

        $document = app(RegisterDocument::class)->handle(
            title: 'Signed intermediate',
            documentTypeId: $this->documentType()->id,
            priority: DocumentPriority::Normal,
            originatingOffice: $office,
            creator: $clerk,
            upload: UploadedFile::fake()->createWithContent('v1.pdf', 'one'),
        );

        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Received,
            actor: $admin,
            expectedMovementId: $document->openMovement->id,
        );

        // v2 becomes current and is signed...
        app(StoreDocumentFile::class)->handle($document, UploadedFile::fake()->createWithContent('v2.pdf', 'two'), $admin);
        $signature = app(SignDocument::class)->handle($document->fresh(), $admin, SignatureMethod::Typed);

        // ...then v3 arrives, making the signed v2 an INTERMEDIATE version.
        app(StoreDocumentFile::class)->handle($document, UploadedFile::fake()->createWithContent('v3.pdf', 'three'), $admin);

        $document->refresh();
        foreach ([MovementAction::Received, MovementAction::Completed] as $action) {
            $document->refresh();
            app(TransitionDocument::class)->handle(
                document: $document,
                action: $action,
                actor: $admin,
                expectedMovementId: $document->openMovement?->id,
            );
        }
        $document->forceFill(['completed_at' => now()->subDays(self::WINDOW_DAYS * 2)])->save();

        $this->artisan('cicto:prune-versions --force')->assertSuccessful();

        $signedFile = DocumentFile::query()->findOrFail($signature->document_file_id);

        // Purging it would leave a Signature Certificate still reporting
        // "valid" for bytes the system deliberately deleted -- worse than a
        // mismatch, because it looks fine.
        $this->assertNull(
            $signedFile->purged_at,
            'A signed version must never be purged.',
        );
        $this->assertTrue($signature->fresh()->load('file')->isValid());
    }

    public function test_a_signed_first_version_does_not_shield_the_intermediate_ones(): void
    {
        config(['cicto.retention.versions.enabled' => true]);

        Storage::fake('documents');
        $office = $this->office();
        $clerk = $this->staff($office);
        $admin = $this->admin($office);

        $document = app(RegisterDocument::class)->handle(
            title: 'Signed original',
            documentTypeId: $this->documentType()->id,
            priority: DocumentPriority::Normal,
            originatingOffice: $office,
            creator: $clerk,
            upload: UploadedFile::fake()->createWithContent('v1.pdf', 'one'),
        );

        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Received,
            actor: $admin,
            expectedMovementId: $document->openMovement->id,
        );

        // v1 -- the original submission -- is the one that gets signed.
        $signature = app(SignDocument::class)->handle($document->fresh(), $admin, SignatureMethod::Typed);

        app(StoreDocumentFile::class)->handle($document, UploadedFile::fake()->createWithContent('v2.pdf', 'two'), $admin);
        app(StoreDocumentFile::class)->handle($document, UploadedFile::fake()->createWithContent('v3.pdf', 'three'), $admin);

        foreach ([MovementAction::Received, MovementAction::Completed] as $action) {
            $document->refresh();
            app(TransitionDocument::class)->handle(
                document: $document,
                action: $action,
                actor: $admin,
                expectedMovementId: $document->openMovement?->id,
            );
        }
        $document->forceFill(['completed_at' => now()->subDays(self::WINDOW_DAYS * 2)])->save();

        $this->artisan('cicto:prune-versions --force')->assertSuccessful();

        $byVersion = DocumentFile::query()
            ->where('document_id', $document->id)
            ->get()
            ->keyBy('version');

        // The signed original and the current version both stay.
        $this->assertNull($byVersion[1]->purged_at, 'the signed original was purged');
        $this->assertNull($byVersion[3]->purged_at, 'the current version was purged');

        // v2 is a genuine intermediate and must go. Excluding signed rows
        // BEFORE the first/last slice made v2 look like the original, so this
        // document reclaimed nothing at all.
        $this->assertNotNull($byVersion[2]->purged_at, 'the intermediate version was spuriously protected');

        $this->assertTrue($signature->fresh()->load('file')->isValid());
    }

    public function test_a_purged_version_returns_410_rather_than_a_broken_download(): void
    {
        config(['cicto.retention.versions.enabled' => true]);

        $document = $this->completedWithVersions(['a', 'b', 'c']);
        $admin = $this->admin($document->originatingOffice);

        $this->artisan('cicto:prune-versions --force')->assertSuccessful();

        $purged = DocumentFile::query()
            ->where('document_id', $document->id)
            ->whereNotNull('purged_at')
            ->firstOrFail();

        $this->actingAs($admin)
            ->get(route('documents.files.download', [$document, $purged]))
            ->assertStatus(410);
    }

    public function test_an_open_document_is_never_pruned_however_old_its_versions(): void
    {
        config(['cicto.retention.versions.enabled' => true]);

        Storage::fake('documents');
        $office = $this->office();

        $document = app(RegisterDocument::class)->handle(
            title: 'Still moving',
            documentTypeId: $this->documentType()->id,
            priority: DocumentPriority::Normal,
            originatingOffice: $office,
            creator: $this->staff($office),
            upload: UploadedFile::fake()->createWithContent('v1.pdf', 'one'),
        );

        app(StoreDocumentFile::class)->handle($document, UploadedFile::fake()->createWithContent('v2.pdf', 'two'), $this->admin($office));
        app(StoreDocumentFile::class)->handle($document, UploadedFile::fake()->createWithContent('v3.pdf', 'three'), $this->admin($office));

        $document->forceFill(['created_at' => now()->subYears(5)])->save();

        $this->artisan('cicto:prune-versions --force')->assertSuccessful();

        // Retention starts when the document FINISHES, not when it was filed.
        $this->assertSame(0, DocumentFile::query()->whereNotNull('purged_at')->count());
    }

    public function test_reuploading_the_current_file_does_not_create_a_version(): void
    {
        $document = $this->completedWithVersions(['A', 'B']);
        $clerk = $document->creator;

        $before = $document->files()->count();

        $file = app(StoreDocumentFile::class)->handle(
            $document,
            UploadedFile::fake()->createWithContent('again.pdf', 'B'),
            $clerk,
        );

        // A double-submitted form must not manufacture a version.
        $this->assertSame($before, $document->files()->count());
        $this->assertSame(2, $file->version);
    }

    public function test_reverting_to_an_older_file_creates_a_new_version(): void
    {
        $document = $this->completedWithVersions(['A', 'B']);
        $clerk = $document->creator;

        $file = app(StoreDocumentFile::class)->handle(
            $document,
            UploadedFile::fake()->createWithContent('revert.pdf', 'A'),
            $clerk,
        );

        // Uploading v1's bytes over v2 is a REVERT and has to become v3.
        // Deduping against any version returned v1, left v2 current, and still
        // reported success -- the replacement silently did nothing.
        $this->assertSame(3, $file->version);
        $this->assertSame(3, $document->fresh()->currentFile->version);
        $this->assertSame(
            hash('sha256', 'A'),
            $document->fresh()->currentFile->checksum_sha256,
        );
    }
}
