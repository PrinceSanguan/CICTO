<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\TransitionDocument;
use App\Enums\MovementAction;
use App\Enums\Role;
use App\Models\Document;
use App\Models\DocumentFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * A write path must never be weaker than the read path on the same record.
 *
 * Adversarial review found two places where it was: act() and uploadVersion()
 * both authorised on "is your office holding this folder", which is plain
 * office_id equality, while DocumentPolicy::view() answered a narrower
 * question. Anyone the read path refused but the write path allowed could move
 * or append to a document they could not open.
 *
 * THE GAP IS NOW CLOSED FROM THE OTHER SIDE. Row access follows office_id for
 * every role (DocumentBuilder::visibleTo), so a clerk at the holding office can
 * read the folder as well as receive it -- which is what the client asked for
 * and what stops a route dying at an office with no Admin on duty. The two
 * paths agree because the read path widened to meet the write path, not because
 * the write path was narrowed.
 *
 * So these tests moved with it. The subject is no longer "a clerk in the
 * holding office" -- that person is now legitimately allowed -- but a clerk in
 * an office WITH NO CONNECTION TO THE DOCUMENT, which is the boundary that
 * still has to hold and the one an id-walking attacker would probe.
 */
class WritePathAuthorizationTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    public function test_a_clerk_cannot_reroute_a_document_they_cannot_open(): void
    {
        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');
        $hrmo = $this->office('HRMO', 'HR');

        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Forwarded,
            actor: $this->admin($mpdo),
            toOfficeId: $mto->id,
            expectedMovementId: $document->openMovement->id,
        );

        // Bob is a plain clerk at an office the document has never touched.
        // HRMO is not holding it, never held it, and did not originate it.
        $bob = $this->staff($hrmo);
        $document->refresh();

        $this->assertFalse($bob->can('view', $document));
        $this->assertFalse(
            $bob->can('act', [$document, MovementAction::Forwarded]),
            'A user who cannot read the document must not be able to move it.',
        );

        $this->actingAs($bob)
            ->get(route('documents.show', $document))
            ->assertForbidden();

        $this->actingAs($bob)
            ->post(route('documents.transitions.store', $document), [
                'action' => 'forwarded',
                'to_office_id' => $hrmo->id,
                'expected_movement_id' => $document->openMovement->id,
            ])
            ->assertForbidden();

        // The folder has not moved.
        $this->assertSame($mto->id, $document->fresh()->openMovement->to_office_id);

        // And the counterpart, stated here so the boundary is unambiguous: the
        // clerk at the office actually holding it may act, because that is the
        // whole point of a queue of offices that receive.
        $this->assertTrue($this->staff($mto)->can('view', $document));
    }

    public function test_a_clerk_cannot_mark_received_a_document_they_cannot_open(): void
    {
        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');
        $hrmo = $this->office('HRMO', 'HR');

        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Forwarded,
            actor: $this->admin($mpdo),
            toOfficeId: $mto->id,
            expectedMovementId: $document->openMovement->id,
        );

        $document->refresh();

        // Received is not a "decision", so it never hit the Admin gate -- the
        // only thing standing between an unrelated clerk and someone else's
        // document is view(), which is exactly why act() calls it first.
        $outsider = $this->staff($hrmo);

        $this->assertFalse($outsider->can('act', [$document, MovementAction::Received]));

        // The clerk at the holding office is not an outsider. This assertion is
        // the client's flow of 2026-09-03 in one line: an office receives, and
        // the office is its staff, not only its department head. Without it a
        // route stalls at the first office whose Admin is on leave.
        $this->assertTrue(
            $this->staff($mto)->can('act', [$document, MovementAction::Received]),
        );

        // Deciding is still the Admin's, though. Widening the ROW did not widen
        // the VERB: 00-architecture.md §7 keeps those two separate.
        $this->assertFalse(
            $this->staff($mto)->can('act', [$document, MovementAction::Completed]),
            'Complete stays Admin-only; only reading and receiving widened.',
        );
    }

    public function test_a_clerk_cannot_upload_a_version_to_a_document_they_cannot_open(): void
    {
        Storage::fake('documents');

        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');
        $hrmo = $this->office('HRMO', 'HR');

        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Forwarded,
            actor: $this->admin($mpdo),
            toOfficeId: $mto->id,
            expectedMovementId: $document->openMovement->id,
        );

        $bob = $this->staff($hrmo);
        $document->refresh();

        $this->assertFalse($bob->can('uploadVersion', $document));

        $this->actingAs($bob)
            ->post(route('documents.files.store', $document), [
                'file' => UploadedFile::fake()->create('planted.pdf', 20, 'application/pdf'),
            ])
            ->assertForbidden();

        $this->assertSame(0, DocumentFile::query()->where('uploaded_by_id', $bob->id)->count());
    }

    public function test_the_office_admin_can_still_do_both(): void
    {
        Storage::fake('documents');

        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');
        $mtoAdmin = $this->admin($mto);

        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Forwarded,
            actor: $this->admin($mpdo),
            toOfficeId: $mto->id,
            expectedMovementId: $document->openMovement->id,
        );

        $document->refresh();

        // The fix must not lock out the people who are supposed to act.
        $this->assertTrue($mtoAdmin->can('view', $document));
        $this->assertTrue($mtoAdmin->can('act', [$document, MovementAction::Received]));
        $this->assertTrue($mtoAdmin->can('uploadVersion', $document));

        $this->actingAs($mtoAdmin)
            ->post(route('documents.files.store', $document), [
                'file' => UploadedFile::fake()->create('revised.pdf', 20, 'application/pdf'),
            ])
            ->assertRedirect();
    }

    public function test_the_read_path_and_the_write_path_agree_for_every_role(): void
    {
        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');

        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Forwarded,
            actor: $this->admin($mpdo),
            toOfficeId: $mto->id,
            expectedMovementId: $document->openMovement->id,
        );

        $document->refresh();

        /*
         * The last three are the ones with something to prove.
         *
         * Every user attached to an office the document has touched can now
         * read it, so a candidate list of only those makes this test vacuous --
         * the loop body never runs and it asserts nothing while still passing.
         * PHPUnit calls that risky, and it is right. An unrelated office and an
         * office-less account are what the read/write agreement is actually
         * about.
         */
        $hrmo = $this->office('HRMO', 'HR');

        $candidates = [
            $this->staff($mto),
            $this->admin($mto),
            $this->staff($mpdo),
            $this->admin($mpdo),
            $this->superAdmin(),
            $this->staff($hrmo),
            $this->admin($hrmo),
            // Signed up for themselves; no administrator has given them an
            // office yet, so there is no office whose work they could see.
            User::factory()->create(['role' => Role::User, 'office_id' => null]),
        ];

        $refused = 0;

        foreach ($candidates as $user) {
            if ($user->can('view', $document)) {
                continue;
            }

            $refused++;

            // Cannot read => must not be able to write, in any form.
            foreach (MovementAction::cases() as $action) {
                $this->assertFalse(
                    $user->can('act', [$document, $action]),
                    "A user who cannot view the document was allowed to {$action->value} it.",
                );
            }

            $this->assertFalse($user->can('uploadVersion', $document));
            $this->assertFalse($user->can('comment', $document));

            // And the list must not surface it either.
            $this->assertFalse(
                Document::query()->visibleTo($user)->whereKey($document->id)->exists(),
                'A document that cannot be opened must not appear in the list.',
            );
        }

        // The guard against this test quietly becoming a no-op again the next
        // time the read path moves.
        $this->assertSame(
            3,
            $refused,
            'Expected the two HRMO accounts and the office-less one to be refused.',
        );
    }
}
