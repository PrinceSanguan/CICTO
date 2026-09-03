<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\ArchiveDocument;
use App\Actions\Documents\TransitionDocument;
use App\Enums\MovementAction;
use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * §16 Archive Management.
 *
 * Archiving is FILING. The row, its files, its signatures and its whole
 * movement history survive; only its membership of the working list changes.
 */
class ArchiveTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    /** Walk a document all the way to Completed so it can be filed. */
    private function completed(): array
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $document = $this->registerDocument($office, $this->staff($office));

        foreach ([
            MovementAction::Received,
            MovementAction::Completed,
        ] as $action) {
            $document->refresh();
            app(TransitionDocument::class)->handle(
                document: $document,
                action: $action,
                actor: $admin,
                expectedMovementId: $document->openMovement?->id,
            );
        }

        return [$document->fresh(), $admin];
    }

    public function test_an_open_document_cannot_be_archived(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $document = $this->registerDocument($office, $this->staff($office));

        // Filing live work would hide it from every queue while it is still
        // somebody's job.
        $this->assertFalse($admin->can('archive', $document));
    }

    public function test_archiving_removes_it_from_the_active_list_but_deletes_nothing(): void
    {
        [$document, $admin] = $this->completed();

        $files = $document->files()->count();
        $movements = $document->movements()->count();

        app(ArchiveDocument::class)->archive($document, $admin, 'Filed after audit');

        $document->refresh();

        $this->assertNotNull($document->archived_at);
        $this->assertSame($admin->id, $document->archived_by_id);
        $this->assertSame('Filed after audit', $document->archive_reason);

        // Gone from the working list...
        $this->assertFalse(
            Document::query()->active()->whereKey($document->id)->exists(),
        );

        // ...but present, whole, in the archive.
        $this->assertTrue(
            Document::query()->archived()->whereKey($document->id)->exists(),
        );
        $this->assertSame($files, $document->files()->count());

        // And the filing itself is in the ledger, as §13 requires.
        $this->assertSame($movements + 1, $document->movements()->count());
        // reorder(), not orderByDesc(): the movements() relation bakes in
        // orderBy('sequence') ASC, and appending a DESC clause leaves the
        // ascending one in front -- so you silently get the FIRST leg.
        $this->assertSame(
            MovementAction::Archived,
            $document->movements()->reorder('sequence', 'desc')->first()->action,
        );
    }

    public function test_archiving_never_opens_a_leg(): void
    {
        [$document, $admin] = $this->completed();

        app(ArchiveDocument::class)->archive($document, $admin);

        // A completed document is nowhere; filing it must not put it back into
        // somebody's inbox, and an open leg here would break the one-open-leg
        // unique index outright.
        $this->assertSame(
            0,
            $document->movements()->whereNull('departed_at')->count(),
        );
        $this->assertNull($document->fresh()->openMovement);
    }

    public function test_restoring_returns_it_to_the_active_list_with_its_status_intact(): void
    {
        [$document, $admin] = $this->completed();
        $status = $document->status;

        app(ArchiveDocument::class)->archive($document, $admin);
        app(ArchiveDocument::class)->restore($document->fresh(), $admin);

        $document->refresh();

        $this->assertNull($document->archived_at);
        $this->assertNull($document->archived_by_id);

        // Restoring is not a re-opening: a completed document comes back
        // completed, not to whatever stage it was at before.
        $this->assertSame($status, $document->status);
        $this->assertTrue(
            Document::query()->active()->whereKey($document->id)->exists(),
        );
    }

    public function test_archiving_twice_is_harmless(): void
    {
        [$document, $admin] = $this->completed();

        app(ArchiveDocument::class)->archive($document, $admin, 'First');
        $count = $document->fresh()->movements()->count();

        app(ArchiveDocument::class)->archive($document->fresh(), $admin, 'Second');

        // A double click adds no second ledger row and does not overwrite the
        // original reason.
        $this->assertSame($count, $document->fresh()->movements()->count());
        $this->assertSame('First', $document->fresh()->archive_reason);
    }

    public function test_a_clerk_cannot_archive_or_restore(): void
    {
        [$document, $admin] = $this->completed();
        $clerk = $this->staff($document->originatingOffice);

        $this->assertFalse($clerk->can('archive', $document));

        $this->actingAs($clerk)
            ->post(route('documents.archive', $document))
            ->assertForbidden();

        app(ArchiveDocument::class)->archive($document, $admin);

        $this->actingAs($clerk)
            ->delete(route('documents.restore', $document->fresh()))
            ->assertForbidden();
    }

    public function test_the_archive_list_is_office_scoped(): void
    {
        [$document, $admin] = $this->completed();
        app(ArchiveDocument::class)->archive($document, $admin);

        $elsewhere = $this->office('HRMO');
        $stranger = $this->admin($elsewhere);

        $mine = $this->actingAs($admin)
            ->get(route('archive.index'))
            ->assertOk()
            ->viewData('page')['props']['documents']['data'];

        $theirs = $this->actingAs($stranger)
            ->get(route('archive.index'))
            ->assertOk()
            ->viewData('page')['props']['documents']['data'];

        $this->assertCount(1, $mine);
        $this->assertCount(0, $theirs);
    }
}
