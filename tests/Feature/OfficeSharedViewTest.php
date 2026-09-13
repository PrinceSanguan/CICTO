<?php

namespace Tests\Feature;

use App\Actions\Documents\TransitionDocument;
use App\Enums\MovementAction;
use App\Models\Document;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * Client, 2026-09-13: an office's clerk and its Admin share one view of the
 * office's documents. Whatever the clerk who submitted a document can follow,
 * the Admin of that office can follow too -- and the other way round.
 */
class OfficeSharedViewTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    /** Filed at the registrar by `$by`, then sent on to audit. */
    private function sentOn(Office $registrar, Office $audit, User $by): Document
    {
        $document = $this->registerDocument($registrar, $by);

        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Forwarded,
            actor: $by,
            toOfficeId: $audit->id,
            expectedMovementId: $document->openMovement->id,
        );

        return $document->refresh();
    }

    public function test_the_office_admin_still_sees_a_clerks_document_after_it_leaves_the_office(): void
    {
        $registrar = $this->office('OCCR', 'Office of the City Civil Registrar');
        $audit = $this->office('COA', 'Commission on Audit');
        $clerk = $this->staff($registrar);
        $admin = $this->admin($registrar);

        $document = $this->sentOn($registrar, $audit, $clerk);

        foreach ([$clerk, $admin] as $viewer) {
            $this->actingAs($viewer)
                ->get(route('dashboard'))
                ->assertInertia(fn (Assert $page) => $page
                    ->component('dashboard')
                    ->where('stats.inbox', 1)
                    ->where('stats.submitted', 1)
                    ->has('recent', 1)
                    ->where('recent.0.id', $document->id)
                    ->where('recent.0.current_office', 'Commission on Audit'));
        }
    }

    public function test_a_clerk_sees_what_is_waiting_in_their_office_just_as_the_admin_does(): void
    {
        $registrar = $this->office('OCCR', 'Office of the City Civil Registrar');
        $audit = $this->office('COA', 'Commission on Audit');

        $document = $this->sentOn($registrar, $audit, $this->staff($registrar));

        foreach ([$this->staff($audit), $this->admin($audit)] as $viewer) {
            $this->actingAs($viewer)
                ->get(route('dashboard'))
                ->assertInertia(fn (Assert $page) => $page
                    ->component('dashboard')
                    ->where('stats.inbox', 1)
                    ->where('stats.submitted', 0)
                    ->has('recent', 1)
                    ->where('recent.0.id', $document->id));
        }
    }

    public function test_an_unrelated_office_sees_nothing_on_its_dashboard(): void
    {
        $registrar = $this->office('OCCR', 'Office of the City Civil Registrar');
        $audit = $this->office('COA', 'Commission on Audit');
        $pio = $this->office('PIO', 'Public Information Office');

        $this->sentOn($registrar, $audit, $this->staff($registrar));

        $this->actingAs($this->admin($pio))
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.inbox', 0)
                ->where('stats.submitted', 0)
                ->has('recent', 0));
    }

    public function test_the_office_admin_can_upload_a_corrected_version_after_the_document_left(): void
    {
        Storage::fake('documents');

        $registrar = $this->office('OCCR', 'Office of the City Civil Registrar');
        $audit = $this->office('COA', 'Commission on Audit');
        $admin = $this->admin($registrar);

        $document = $this->sentOn($registrar, $audit, $this->staff($registrar));

        $this->assertTrue($admin->can('uploadVersion', $document));

        $this->actingAs($admin)
            ->post(route('documents.files.store', $document), [
                'file' => UploadedFile::fake()->create('corrected.pdf', 20, 'application/pdf'),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }
}
