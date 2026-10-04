<?php

namespace Tests\Feature\SuperAdmin;

use App\Enums\SecurityEventType;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Office;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Support\RoutePlan;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\OfficeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * The Document Types page (client request, 2026-10-02; approved 2026-10-03):
 * "mag dagdag ng documents type tapos i input na rin po don yung offices na
 * dadaanan nya, para sa automation".
 *
 * A Super Admin adds a type and its offices; the Submit form's Automatic mode
 * fills the route in from it, exactly as it does for the built-in types. The
 * seeder that every deploy re-runs never touches a custom one. Editing the
 * built-in types (2026-10-04) is BuiltInDocumentTypeTest.
 */
class DocumentTypeManagementTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([OfficeSeeder::class, DocumentTypeSeeder::class]);
    }

    private function officeId(string $code): int
    {
        return Office::query()->where('code', $code)->valueOrFail('id');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Special Event Permit',
            'code' => 'EVENT-PERMIT',
            'description' => 'One-day events in a public place.',
            'turnaround_days' => 5,
            'steps' => [
                ['office_id' => $this->officeId('BPLO'), 'optional' => false, 'purpose' => 'Receives the application'],
                ['office_id' => $this->officeId('CLO'), 'optional' => true, 'purpose' => 'Legal review, if needed'],
                ['office_id' => $this->officeId('OCM'), 'optional' => false, 'purpose' => null],
                ['office_id' => $this->officeId('BPLO'), 'optional' => false, 'purpose' => 'Release'],
            ],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function create(?User $actor = null, array $overrides = []): TestResponse
    {
        return $this->actingAs($actor ?? $this->superAdmin())
            ->from(route('super-admin.document-types.index'))
            ->post(route('super-admin.document-types.store'), $this->payload($overrides));
    }

    private function customType(): DocumentType
    {
        $this->create()->assertSessionHasNoErrors();

        return DocumentType::query()->where('code', 'EVENT-PERMIT')->firstOrFail();
    }

    /**
     * @param  list<int>  $officeIds
     */
    private function submit(User $user, DocumentType $type, array $officeIds): TestResponse
    {
        Storage::fake('documents');

        return $this->actingAs($user)->post(route('documents.store'), [
            'title' => 'Barangay fiesta permit',
            'document_type_id' => $type->id,
            'office_ids' => $officeIds,
            'priority' => 'normal',
            'file' => UploadedFile::fake()->create('permit.pdf', 40, 'application/pdf'),
        ]);
    }

    /**
     * What the Submit form's Automatic mode posts: the filer, then every
     * step with an office that is not an unticked optional one, with
     * neighbours merged.
     *
     * @return list<int>
     */
    private function automaticRoute(User $clerk, DocumentType $type): array
    {
        $props = Assert::fromTestResponse(
            $this->actingAs($clerk)->get(route('documents.create'))->assertOk(),
        )->toArray()['props'];

        $ids = [$clerk->office_id];

        foreach ($props['routeTemplates'][$type->id]['steps'] as $step) {
            if ($step['office_id'] !== null && (! $step['optional'] || $step['checked'])) {
                $ids[] = $step['office_id'];
            }
        }

        return RoutePlan::collapse(array_map('intval', $ids));
    }

    // ── Access ────────────────────────────────────────────────────────────

    public function test_only_a_super_admin_can_reach_the_page(): void
    {
        $type = $this->customType();
        $office = Office::query()->where('code', 'BPLO')->firstOrFail();

        foreach ([$this->admin($office), $this->staff($office)] as $user) {
            $this->actingAs($user)->get(route('super-admin.document-types.index'))->assertForbidden();
            $this->actingAs($user)->post(route('super-admin.document-types.store'), $this->payload(['code' => 'X-'.$user->id, 'name' => 'X '.$user->id]))->assertForbidden();
            $this->actingAs($user)->patch(route('super-admin.document-types.update', $type), $this->payload(['name' => 'Renamed']))->assertForbidden();
            $this->actingAs($user)->patch(route('super-admin.document-types.status', $type), ['is_active' => false])->assertForbidden();
        }

        $this->assertSame(1, DocumentType::query()->where('is_custom', true)->count());
        $this->assertSame('Special Event Permit', $type->refresh()->name);
        $this->assertTrue($type->is_active);

        $this->actingAs($this->superAdmin())
            ->get(route('super-admin.document-types.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('super-admin/document-types/index')
                ->has('types', DocumentType::query()->count())
                ->where('maxSteps', RoutePlan::maxStops() - 1));
    }

    // ── Creating, and the Submit form ────────────────────────────────────

    public function test_a_new_type_fills_the_automatic_route_and_registers_its_stops_in_order(): void
    {
        $this->create()->assertSessionHasNoErrors()->assertRedirect(route('super-admin.document-types.index'));

        $type = DocumentType::query()->where('code', 'EVENT-PERMIT')->firstOrFail();

        $this->assertTrue($type->is_custom);
        $this->assertTrue($type->is_active);
        $this->assertFalse($type->is_confidential);
        $this->assertFalse($type->allows_broadcast);
        $this->assertSame(5, $type->turnaround_days);
        $this->assertSame(
            [$this->officeId('BPLO'), $this->officeId('CLO'), $this->officeId('OCM'), $this->officeId('BPLO')],
            $type->routeSteps()->pluck('office_id')->all(),
        );
        $this->assertSame([1, 2, 3, 4], $type->routeSteps()->pluck('position')->all());

        $clerk = $this->staff(Office::query()->where('code', 'CENRO')->firstOrFail());

        $this->actingAs($clerk)
            ->get(route('documents.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('documentTypes', fn ($types) => collect($types)->contains('id', $type->id))
                ->where('routeTemplates.'.$type->id.'.confidential', false)
                ->where('routeTemplates.'.$type->id.'.broadcast', false)
                ->where('routeTemplates.'.$type->id.'.note', null)
                ->has('routeTemplates.'.$type->id.'.steps', 4)
                ->where('routeTemplates.'.$type->id.'.steps.0', [
                    'kind' => 'office',
                    'office_id' => $this->officeId('BPLO'),
                    'missing_office' => null,
                    'purpose' => 'Receives the application',
                    'optional' => false,
                    'checked' => false,
                ])
                ->where('routeTemplates.'.$type->id.'.steps.1.optional', true)
                ->where('routeTemplates.'.$type->id.'.steps.1.checked', false)
                // No purpose is an empty string, as on a coded step.
                ->where('routeTemplates.'.$type->id.'.steps.2.purpose', ''));

        // Automatic mode, the optional legal review left unticked.
        $route = $this->automaticRoute($clerk, $type);

        $this->assertSame(
            [$this->officeId('CENRO'), $this->officeId('BPLO'), $this->officeId('OCM'), $this->officeId('BPLO')],
            $route,
        );

        $this->submit($clerk, $type, $route)->assertSessionHasNoErrors();

        $document = Document::query()->firstOrFail();

        $this->assertSame($type->id, $document->document_type_id);
        $this->assertSame(
            [$this->officeId('BPLO'), $this->officeId('OCM'), $this->officeId('BPLO')],
            $document->routeStops()->pluck('office_id')->all(),
        );
    }

    public function test_a_custom_step_has_exactly_the_shape_of_a_built_in_office_step(): void
    {
        $type = $this->customType();
        $dv = DocumentType::query()->where('code', 'DV')->firstOrFail();

        $props = Assert::fromTestResponse(
            $this->actingAs($this->staff(Office::query()->where('code', 'CENRO')->firstOrFail()))
                ->get(route('documents.create')),
        )->toArray()['props'];

        $this->assertSame(
            array_keys($props['routeTemplates'][$dv->id]['steps'][0]),
            array_keys($props['routeTemplates'][$type->id]['steps'][0]),
        );
        $this->assertSame(
            array_keys($props['routeTemplates'][$dv->id]),
            array_keys($props['routeTemplates'][$type->id]),
        );
    }

    public function test_a_filer_whose_office_is_the_first_step_gets_one_stop_not_two(): void
    {
        $type = $this->customType();
        $bplo = $this->staff(Office::query()->where('code', 'BPLO')->firstOrFail());

        $route = $this->automaticRoute($bplo, $type);

        $this->submit($bplo, $type, $route)->assertSessionHasNoErrors();

        $this->assertSame(
            [$this->officeId('OCM'), $this->officeId('BPLO')],
            Document::query()->firstOrFail()->routeStops()->pluck('office_id')->all(),
        );
    }

    public function test_an_office_deactivated_after_saving_is_named_and_left_out(): void
    {
        $type = $this->customType();
        Office::query()->where('code', 'OCM')->update(['is_active' => false]);

        $this->actingAs($this->staff(Office::query()->where('code', 'CENRO')->firstOrFail()))
            ->get(route('documents.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('routeTemplates.'.$type->id.'.steps.2.office_id', null)
                ->where('routeTemplates.'.$type->id.'.steps.2.missing_office', 'Office of the City Mayor')
                ->where('routeTemplates.'.$type->id.'.steps.0.missing_office', null));
    }

    // ── Validation ───────────────────────────────────────────────────────

    public function test_the_code_is_capitals_digits_and_hyphens_up_to_32(): void
    {
        foreach (['event-permit', 'EVENT PERMIT', 'EVENT_PERMIT', 'ÉVENT', str_repeat('A', 33)] as $index => $code) {
            $this->create(overrides: ['code' => $code, 'name' => "Bad code {$index}"])->assertSessionHasErrors('code');
        }

        $this->create(overrides: ['code' => str_repeat('A', 32)])->assertSessionHasNoErrors();
        $this->assertSame(1, DocumentType::query()->where('is_custom', true)->count());
    }

    public function test_the_code_must_be_unique_including_a_soft_deleted_type(): void
    {
        $this->customType();

        $this->create(overrides: ['name' => 'Another one'])
            ->assertSessionHasErrors(['code' => 'Another document type uses that code, or once did. Choose another.']);

        DocumentType::factory()->create(['code' => 'GONE', 'name' => 'Gone'])->delete();

        $this->create(overrides: ['code' => 'GONE', 'name' => 'Gone again'])
            ->assertSessionHasErrors(['code' => 'Another document type uses that code, or once did. Choose another.']);
    }

    public function test_a_built_in_code_is_refused(): void
    {
        foreach (['DV', 'BUSINESS-PERMIT', 'CONFIDENTIAL'] as $code) {
            $this->create(overrides: ['code' => $code, 'name' => "Mine {$code}"])
                ->assertSessionHasErrors(['code' => 'That code belongs to a built-in document type. Choose another.']);
        }

        // Even on an installation where the seeder has not run yet.
        DocumentType::query()->where('code', 'PAYROLL')->forceDelete();

        $this->create(overrides: ['code' => 'PAYROLL', 'name' => 'My payroll'])
            ->assertSessionHasErrors(['code' => 'That code belongs to a built-in document type. Choose another.']);
    }

    public function test_the_code_cannot_be_changed_after_the_type_is_created(): void
    {
        $type = $this->customType();

        $this->actingAs($this->superAdmin())
            ->patch(route('super-admin.document-types.update', $type), $this->payload(['code' => 'NEW-CODE']))
            ->assertSessionHasErrors(['code' => 'The code cannot be changed after the type is created.']);

        $this->assertSame('EVENT-PERMIT', $type->refresh()->code);

        // Sending none, as the page does, is an edit like any other.
        $payload = $this->payload(['name' => 'Event Permit']);
        unset($payload['code']);

        $this->actingAs($this->superAdmin())
            ->patch(route('super-admin.document-types.update', $type), $payload)
            ->assertSessionHasNoErrors();

        $this->assertSame('Event Permit', $type->refresh()->name);
        $this->assertSame('EVENT-PERMIT', $type->code);
    }

    public function test_the_name_is_unique_whatever_the_capitals(): void
    {
        $this->create(overrides: ['code' => 'MINE-DV', 'name' => 'disbursement VOUCHER'])
            ->assertSessionHasErrors(['name' => 'Another document type already has that name.']);

        $type = $this->customType();

        // Its own name is not a clash with itself.
        $this->actingAs($this->superAdmin())
            ->patch(route('super-admin.document-types.update', $type), $this->payload())
            ->assertSessionHasNoErrors();
    }

    public function test_the_route_needs_one_office_and_at_most_every_stop_but_the_filer_s(): void
    {
        $this->create(overrides: ['steps' => []])
            ->assertSessionHasErrors(['steps' => 'Add at least one office to the route.']);

        $active = Office::query()->where('is_active', true)->pluck('id')->values();
        $max = RoutePlan::maxStops() - 1;
        $alternating = fn (int $count): array => array_map(
            fn (int $i): array => ['office_id' => $active[$i % 2], 'optional' => false],
            range(0, $count - 1),
        );

        $this->create(overrides: ['steps' => $alternating($max + 1)])
            ->assertSessionHasErrors(['steps' => "A route can have at most {$max} offices after the one filing it."]);

        $this->create(overrides: ['steps' => $alternating($max)])->assertSessionHasNoErrors();
    }

    public function test_every_office_on_the_route_must_be_active(): void
    {
        Office::query()->where('code', 'CLO')->update(['is_active' => false]);

        $this->create()
            ->assertSessionHasErrors(['steps.1.office_id' => 'An office on the route is no longer active. Remove it or choose another.']);

        $this->create(overrides: ['steps' => [['office_id' => 999999]]])
            ->assertSessionHasErrors('steps.0.office_id');

        $this->assertSame(0, DocumentType::query()->where('is_custom', true)->count());
    }

    public function test_the_same_office_twice_in_a_row_is_refused_but_a_return_later_is_not(): void
    {
        $this->create(overrides: ['steps' => [
            ['office_id' => $this->officeId('BPLO')],
            ['office_id' => $this->officeId('OCM')],
            ['office_id' => $this->officeId('OCM')],
        ]])->assertSessionHasErrors(['steps' => 'The same office is listed twice in a row. An office can come round again, but not straight after itself.']);

        $this->assertSame(0, DocumentType::query()->where('is_custom', true)->count());

        // The default payload comes back to BPLO for release: accepted.
        $this->create()->assertSessionHasNoErrors();
    }

    public function test_turnaround_is_optional_and_between_1_and_365_days(): void
    {
        foreach ([0, 366, 'soon'] as $index => $days) {
            $this->create(overrides: ['turnaround_days' => $days, 'code' => "T-{$index}", 'name' => "T {$index}"])
                ->assertSessionHasErrors('turnaround_days');
        }

        $this->create(overrides: ['turnaround_days' => null])->assertSessionHasNoErrors();
        $this->assertNull(DocumentType::query()->where('code', 'EVENT-PERMIT')->value('turnaround_days'));

        $this->create(overrides: ['turnaround_days' => 365, 'code' => 'LONG', 'name' => 'Long one'])->assertSessionHasNoErrors();
    }

    // ── Documents already registered ─────────────────────────────────────

    public function test_editing_a_route_leaves_a_registered_document_s_stops_alone(): void
    {
        $type = $this->customType();
        $clerk = $this->staff(Office::query()->where('code', 'CENRO')->firstOrFail());

        $this->submit($clerk, $type, $this->automaticRoute($clerk, $type))->assertSessionHasNoErrors();
        $document = Document::query()->firstOrFail();
        $stops = $document->routeStops()->get(['position', 'office_id', 'status'])->toArray();

        $this->actingAs($this->superAdmin())
            ->patch(route('super-admin.document-types.update', $type), $this->payload([
                'steps' => [
                    ['office_id' => $this->officeId('TREA')],
                    ['office_id' => $this->officeId('ARO')],
                ],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            [$this->officeId('TREA'), $this->officeId('ARO')],
            $type->routeSteps()->pluck('office_id')->all(),
        );
        $this->assertSame($stops, $document->routeStops()->get(['position', 'office_id', 'status'])->toArray());

        // And deactivating the type leaves it alone too.
        $this->actingAs($this->superAdmin())
            ->patch(route('super-admin.document-types.status', $type), ['is_active' => false])
            ->assertSessionHasNoErrors();

        $this->assertSame($stops, $document->routeStops()->get(['position', 'office_id', 'status'])->toArray());
        $this->assertSame($type->id, $document->refresh()->document_type_id);
    }

    public function test_deactivating_hides_the_type_from_submit_document_only(): void
    {
        $type = $this->customType();
        $clerk = $this->staff(Office::query()->where('code', 'CENRO')->firstOrFail());
        $route = $this->automaticRoute($clerk, $type);

        $this->submit($clerk, $type, $route)->assertSessionHasNoErrors();
        $document = Document::query()->firstOrFail();

        $this->actingAs($this->superAdmin())
            ->patch(route('super-admin.document-types.status', $type), ['is_active' => false])
            ->assertSessionHasNoErrors();

        $this->assertFalse($type->refresh()->is_active);

        // Gone from the form, and refused if posted anyway.
        $this->actingAs($clerk)
            ->get(route('documents.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('documentTypes', fn ($types) => ! collect($types)->contains('id', $type->id))
                ->missing('routeTemplates.'.$type->id));

        $this->submit($clerk, $type, $route)->assertSessionHasErrors('document_type_id');
        $this->assertSame(1, Document::query()->count());

        // Still named on the existing document, through the type filter...
        $this->actingAs($clerk)
            ->get(route('documents.index', ['document_type_id' => $type->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('documents.data', 1)
                ->where('documents.data.0.id', $document->id)
                ->where('documents.data.0.document_type', 'Special Event Permit'));

        // ...and in the reports.
        $this->actingAs($this->superAdmin())
            ->getJson(route('reports.activity.documents'))
            ->assertOk()
            ->assertJsonPath('data.0.id', $document->id)
            ->assertJsonPath('data.0.type', 'Special Event Permit');

        // And it comes back.
        $this->actingAs($this->superAdmin())
            ->patch(route('super-admin.document-types.status', $type), ['is_active' => true])
            ->assertSessionHasNoErrors();

        $this->actingAs($clerk)
            ->get(route('documents.create'))
            ->assertInertia(fn (Assert $page) => $page->has('routeTemplates.'.$type->id));
    }

    // ── The seeder ───────────────────────────────────────────────────────

    public function test_running_the_seeder_twice_leaves_custom_types_alone(): void
    {
        $type = $this->customType();

        $this->actingAs($this->superAdmin())
            ->patch(route('super-admin.document-types.status', $type), ['is_active' => false]);

        // A custom type whose code the seeder retires, on an installation
        // that never had the placeholder.
        $this->create(overrides: ['code' => 'PR', 'name' => 'Purchase Request'])->assertSessionHasNoErrors();
        $pr = DocumentType::query()->where('code', 'PR')->firstOrFail();

        $before = DocumentType::query()->where('is_custom', true)->orderBy('id')->get()
            ->map(fn (DocumentType $type) => $type->only(['id', 'code', 'name', 'description', 'turnaround_days', 'is_active', 'sort_order', 'is_confidential', 'allows_broadcast']))
            ->all();
        $steps = $type->routeSteps()->get(['position', 'office_id', 'is_optional', 'purpose'])->toArray();

        $this->seed(DocumentTypeSeeder::class);
        $this->seed(DocumentTypeSeeder::class);

        $this->assertSame($before, DocumentType::query()->where('is_custom', true)->orderBy('id')->get()
            ->map(fn (DocumentType $type) => $type->only(['id', 'code', 'name', 'description', 'turnaround_days', 'is_active', 'sort_order', 'is_confidential', 'allows_broadcast']))
            ->all());
        $this->assertFalse($type->refresh()->is_active, 'The seeder reactivated a custom type.');
        $this->assertTrue($pr->refresh()->is_active, 'The seeder retired a custom type.');
        $this->assertSame($steps, $type->routeSteps()->get(['position', 'office_id', 'is_optional', 'purpose'])->toArray());
        $this->assertSame(count(DocumentTypeSeeder::BUILT_IN_TYPES), DocumentType::query()->where('is_custom', false)->where('is_active', true)->count());
    }

    public function test_the_seeder_skips_a_built_in_code_a_custom_type_somehow_holds(): void
    {
        // Validation refuses this; the seeder must not depend on that.
        $payroll = DocumentType::query()->where('code', 'PAYROLL')->firstOrFail();
        $payroll->forceFill(['is_custom' => true, 'name' => 'Our own payroll', 'is_active' => false])->save();

        $this->seed(DocumentTypeSeeder::class);

        $payroll->refresh();
        $this->assertSame('Our own payroll', $payroll->name);
        $this->assertFalse($payroll->is_active);
        $this->assertSame(1, DocumentType::query()->where('code', 'PAYROLL')->count());
    }

    // ── The security log ─────────────────────────────────────────────────

    public function test_each_change_is_written_to_the_security_log_once(): void
    {
        $actor = $this->superAdmin();
        $events = fn () => SecurityEvent::query()->ofType(SecurityEventType::SettingChanged)->orderBy('id')->get();

        $this->create($actor)->assertSessionHasNoErrors();
        $type = DocumentType::query()->where('code', 'EVENT-PERMIT')->firstOrFail();

        $this->assertCount(1, $events());
        $this->assertSame('Document type "Special Event Permit" (EVENT-PERMIT) added, with a route of 4 offices.', $events()->last()->summary);
        $this->assertSame($actor->id, $events()->last()->user_id);
        $this->assertSame('Document type EVENT-PERMIT', $events()->last()->subject_label);

        // Saved with nothing changed: not a change.
        $this->actingAs($actor)->patch(route('super-admin.document-types.update', $type), $this->payload())->assertSessionHasNoErrors();
        $this->assertCount(1, $events());

        $this->actingAs($actor)->patch(route('super-admin.document-types.update', $type), $this->payload([
            'turnaround_days' => 7,
            'steps' => [['office_id' => $this->officeId('OCM')]],
        ]))->assertSessionHasNoErrors();

        $this->assertCount(2, $events());
        $this->assertSame('Document type "Special Event Permit" (EVENT-PERMIT) changed: turnaround days, route.', $events()->last()->summary);

        $this->actingAs($actor)->patch(route('super-admin.document-types.status', $type), ['is_active' => false]);
        // Pressed twice: one transition.
        $this->actingAs($actor)->patch(route('super-admin.document-types.status', $type), ['is_active' => false]);

        $this->assertCount(3, $events());
        $this->assertStringContainsString('deactivated', $events()->last()->summary);

        $this->actingAs($actor)->patch(route('super-admin.document-types.status', $type), ['is_active' => true]);

        $this->assertCount(4, $events());
        $this->assertStringContainsString('activated', $events()->last()->summary);
    }
}
