<?php

namespace Tests\Feature\Documents;

use App\Enums\MovementAction;
use App\Models\Document;
use App\Models\DocumentRouteStop;
use App\Models\Office;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/** TEMPORARY QA PROBES -- delete before commit. */
class DepartmentSubmitProbeTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    private function bag(): array
    {
        $errors = session()->get('errors');

        return is_object($errors) ? $errors->getBag('default')->messages() : (array) $errors;
    }

    /** @param array<string, mixed> $overrides */
    private function submit(array $overrides = [], ?Office $home = null)
    {
        Storage::fake('documents');

        $home ??= $this->office('MPDO', 'Planning Office');

        return $this->actingAs($this->staff($home))
            ->post(route('documents.store'), array_merge([
                'title' => 'Request for office supplies',
                'document_type_id' => $this->documentType()->id,
                'office_ids' => [$home->id],
                'priority' => 'normal',
                'file' => UploadedFile::fake()->create('request.pdf', 40, 'application/pdf'),
            ], $overrides));
    }

    public function test_probe_single_department_is_the_common_case(): void
    {
        $this->submit()->assertSessionHasNoErrors()->assertRedirect();

        $document = Document::query()->firstOrFail();
        $this->assertStringStartsWith('MPDO-', $document->control_number);
        $this->assertSame(0, DocumentRouteStop::query()->count(), 'One department must queue nothing.');
        dump('SINGLE toast: '.session('toast')['message']);
    }

    public function test_probe_no_department(): void
    {
        $response = $this->submit(['office_ids' => []]);
        dump('EMPTY errors: ', $this->bag());
        $response->assertSessionHasErrors();
        $this->assertSame(0, Document::query()->count());
    }

    public function test_probe_missing_department_key_entirely(): void
    {
        $home = $this->office('MPDO', 'Planning Office');
        Storage::fake('documents');

        $this->actingAs($this->staff($home))
            ->post(route('documents.store'), [
                'title' => 'No department at all',
                'document_type_id' => $this->documentType()->id,
                'priority' => 'normal',
                'file' => UploadedFile::fake()->create('request.pdf', 40, 'application/pdf'),
            ]);

        dump('ABSENT errors: ', $this->bag());
    }

    public function test_probe_duplicate_department(): void
    {
        $home = $this->office('MPDO', 'Planning Office');
        $this->submit(['office_ids' => [$home->id, $home->id]], $home);
        dump('DUPLICATE errors: ', $this->bag());
        $this->assertSame(0, Document::query()->count());
    }

    public function test_probe_deactivated_department(): void
    {
        $home = $this->office('MPDO', 'Planning Office');
        $dead = Office::factory()->create(['code' => 'OLD', 'name' => 'Abolished Office', 'is_active' => false]);

        $this->submit(['office_ids' => [$home->id, $dead->id]], $home);
        dump('INACTIVE errors: ', $this->bag());
    }

    public function test_probe_twenty_one_departments(): void
    {
        $home = $this->office('MPDO', 'Planning Office');
        $ids = [$home->id];

        for ($i = 0; $i < 21; $i++) {
            $ids[] = $this->office('O'.$i, 'Office '.$i)->id;
        }

        $this->submit(['office_ids' => $ids], $home);
        dump('MAX errors: ', $this->bag());
    }

    public function test_probe_scalar_alias_error_is_mirrored(): void
    {
        $home = $this->office('MPDO', 'Planning Office');
        $dead = Office::factory()->create(['code' => 'OLD', 'name' => 'Abolished', 'is_active' => false]);
        Storage::fake('documents');

        $this->actingAs($this->staff($home))
            ->post(route('documents.store'), [
                'title' => 'Old tab',
                'document_type_id' => $this->documentType()->id,
                'originating_office_id' => $dead->id,
                'priority' => 'normal',
                'file' => UploadedFile::fake()->create('request.pdf', 40, 'application/pdf'),
            ]);

        dump('ALIAS errors: ', $this->bag());
    }

    public function test_probe_the_folder_visits_every_department_in_order(): void
    {
        $mpdo = $this->office('MPDO', 'Planning Office');
        $mto = $this->office('MTO', 'Treasury');
        $hrmo = $this->office('HRMO', 'Human Resource');

        $this->submit(['office_ids' => [$mpdo->id, $mto->id, $hrmo->id]], $mpdo)
            ->assertSessionHasNoErrors();

        $document = Document::query()->firstOrFail();
        dump('ROUTE toast: '.session('toast')['message']);

        $visited = [$document->openMovement->to_office_id];

        $this->post(route('documents.transitions.store', $document), [
            'action' => 'received',
            'expected_movement_id' => $document->openMovement->id,
        ]);

        foreach ([$mpdo, $mto, $hrmo] as $office) {
            $document->refresh();

            $this->actingAs($this->admin($office))
                ->post(route('documents.transitions.store', $document), [
                    'action' => MovementAction::Approved->value,
                    'expected_movement_id' => $document->openMovement?->id,
                ])->assertSessionHasNoErrors();

            $document->refresh();
            $visited[] = $document->openMovement?->to_office_id;
        }

        dump('VISIT ORDER: ', $visited, 'expected start '.$mpdo->id.' then '.$mto->id.' then '.$hrmo->id);
        dump('STOPS: ', $document->routeStops()->get()->map(fn ($s) => [$s->position, $s->office_id, $s->status->value])->all());
        dump('STATUS: '.$document->status->value);
    }
}
