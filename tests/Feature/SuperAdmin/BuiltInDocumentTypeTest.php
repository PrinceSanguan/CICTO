<?php

namespace Tests\Feature\SuperAdmin;

use App\Enums\RouteStepKind;
use App\Enums\SecurityEventType;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\DocumentTypeRouteStep;
use App\Models\Office;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Support\RoutePlan;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\OfficeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * The 43 built-in document types, editable on the Document Types page
 * (client request, 2026-10-04): name, description, turnaround, the route --
 * offices added, removed, reordered, required or optional, with what happens
 * at each -- and whether the type is offered at all.
 *
 * The database is the route's source of truth; App\Support\RouteTemplates
 * only holds the originals, which the seeder keeps current on every type a
 * Super Admin has not changed.
 */
class BuiltInDocumentTypeTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([OfficeSeeder::class, DocumentTypeSeeder::class]);
        $this->superAdmin = $this->superAdmin();
    }

    private function officeId(string $code): int
    {
        return Office::query()->where('code', $code)->valueOrFail('id');
    }

    private function type(string $code): DocumentType
    {
        return DocumentType::query()->where('code', $code)->firstOrFail();
    }

    private function clerk(string $office = 'CICTO'): User
    {
        return $this->staff(Office::query()->where('code', $office)->firstOrFail());
    }

    /**
     * The Document Types page's row for one type.
     *
     * @return array<string, mixed>
     */
    private function row(string $code): array
    {
        $props = Assert::fromTestResponse(
            $this->actingAs($this->superAdmin)->get(route('super-admin.document-types.index'))->assertOk(),
        )->toArray()['props'];

        return collect($props['types'])->firstWhere('code', $code);
    }

    /**
     * What the page posts for a type, untouched: TypeForm's transform.
     *
     * @return array<string, mixed>
     */
    private function payload(string $code): array
    {
        $row = $this->row($code);
        $payload = [
            'name' => $row['name'],
            'description' => $row['description'],
            'turnaround_days' => $row['turnaround_days'],
        ];

        if ($row['route_editable']) {
            $payload['steps'] = array_map(static fn (array $step): array => $step['kind'] === 'office'
                ? ['kind' => 'office', 'office_id' => $step['office_id'], 'optional' => $step['optional'], 'checked' => $step['optional'] && $step['checked'], 'purpose' => $step['purpose']]
                : ['kind' => $step['kind'], 'step_id' => $step['id'], 'optional' => $step['optional'], 'purpose' => $step['purpose']],
                $row['steps'],
            );
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function save(string $code, array $payload, ?User $actor = null): TestResponse
    {
        return $this->actingAs($actor ?? $this->superAdmin)
            ->from(route('super-admin.document-types.index'))
            ->patch(route('super-admin.document-types.update', $this->type($code)), $payload);
    }

    /**
     * An office step as the page posts it.
     *
     * @return array<string, mixed>
     */
    private function office(string $code, ?string $purpose = null, bool $optional = false, bool $checked = false): array
    {
        return ['kind' => 'office', 'office_id' => $this->officeId($code), 'optional' => $optional, 'checked' => $checked, 'purpose' => $purpose];
    }

    /**
     * The Submit form's templates, by type code.
     *
     * @return array<string, array<string, mixed>>
     */
    private function templates(?User $clerk = null): array
    {
        $props = Assert::fromTestResponse(
            $this->actingAs($clerk ?? $this->clerk())->get(route('documents.create'))->assertOk(),
        )->toArray()['props'];

        $codes = DocumentType::query()->pluck('code', 'id');

        return collect($props['routeTemplates'])->mapWithKeys(fn ($template, $id) => [$codes[$id] => $template])->all();
    }

    /**
     * What Automatic mode posts for a route of fixed offices: the filer, then
     * every office not left unticked, neighbours merged.
     *
     * @return list<int>
     */
    private function automaticRoute(User $clerk, string $code): array
    {
        $ids = [$clerk->office_id];

        foreach ($this->templates($clerk)[$code]['steps'] as $step) {
            if ($step['kind'] === 'office' && $step['office_id'] !== null && (! $step['optional'] || $step['checked'])) {
                $ids[] = $step['office_id'];
            }
        }

        return RoutePlan::collapse(array_map('intval', $ids));
    }

    /**
     * @param  list<int>  $officeIds
     */
    private function submit(User $user, string $code, array $officeIds): TestResponse
    {
        Storage::fake('documents');

        return $this->actingAs($user)->post(route('documents.store'), [
            'title' => 'Voucher for office supplies',
            'document_type_id' => $this->type($code)->id,
            'office_ids' => $officeIds,
            'priority' => 'normal',
            'file' => UploadedFile::fake()->create('voucher.pdf', 40, 'application/pdf'),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function savedRoute(string $code): array
    {
        return $this->type($code)->routeSteps()->get()
            ->map(fn (DocumentTypeRouteStep $step) => $step->definition())
            ->all();
    }

    private function changes(): int
    {
        return SecurityEvent::query()->ofType(SecurityEventType::SettingChanged)->count();
    }

    // ── The page ─────────────────────────────────────────────────────────

    public function test_every_built_in_type_is_offered_for_editing_with_its_saved_route(): void
    {
        $dv = $this->row('DV');

        $this->assertTrue($dv['editable']);
        $this->assertTrue($dv['route_editable']);
        $this->assertFalse($dv['customized']);
        $this->assertCount(13, $dv['steps']);
        $this->assertSame($this->officeId('CA'), $dv['steps'][0]['office_id']);
        $this->assertSame(['optional' => true, 'checked' => true, 'purpose' => 'BAC committee member'], array_intersect_key($dv['steps'][4], array_flip(['optional', 'checked', 'purpose'])));

        // The steps that are not fixed offices come along, by kind.
        $permit = $this->row('PERMIT');
        $this->assertSame(['choose', 'office', 'same'], array_column($permit['steps'], 'kind'));
        $this->assertSame($permit['steps'][0]['id'], $permit['steps'][2]['same_as']);
        $this->assertSame('Any office · starts as the office filing it', $permit['steps'][0]['detail']);
        $this->assertSame('One of: Office of the City Mayor, Office of the City Human Resource Management Officer · starts as Office of the City Mayor', $this->row('CONFIDENTIAL')['steps'][0]['detail']);
        $this->assertContains('note', array_column($this->row('EXEC-ORDER')['steps'], 'kind'));

        // Confidential: editable, but its route is config's.
        $this->assertTrue($this->row('CONFIDENTIAL')['editable']);
        $this->assertFalse($this->row('CONFIDENTIAL')['route_editable']);
        $this->assertSame(
            'Confidential: it goes straight to the City Mayor or HRMO as soon as it is filed, and only you and the people of the office it goes to can see it.',
            $this->row('CONFIDENTIAL')['route_note'],
        );

        foreach (DocumentTypeSeeder::builtInCodes() as $code) {
            $this->assertTrue($this->type($code)->routeSteps()->exists(), "{$code} has no saved route.");
        }
    }

    /**
     * The proof that moving the routes into the database lost nothing the
     * page cannot round-trip: every type saved back as it is accepted, with
     * nothing changed, nothing logged and nothing taken from the seeder.
     */
    public function test_every_built_in_type_saves_back_unchanged(): void
    {
        $before = $this->templates();

        foreach (DocumentTypeSeeder::builtInCodes() as $code) {
            $this->save($code, $this->payload($code))->assertSessionHasNoErrors();
        }

        $this->assertSame(0, $this->changes());
        $this->assertSame(0, DocumentType::query()->whereNotNull('customized_at')->orWhereNotNull('route_customized_at')->count());
        $this->assertSame($before, $this->templates());
    }

    // ── Editing ──────────────────────────────────────────────────────────

    public function test_editing_a_built_in_type_changes_what_submit_document_offers(): void
    {
        Carbon::setTestNow('2026-10-05 09:00:00');

        $clerk = $this->clerk('CICTO');
        $filed = $this->submit($clerk, 'DV', $this->automaticRoute($clerk, 'DV'));
        $filed->assertSessionHasNoErrors();
        $earlier = Document::query()->firstOrFail();
        $earlierStops = $earlier->routeStops()->get(['position', 'office_id', 'status'])->toArray();

        $this->save('DV', [
            'name' => 'Disbursement Voucher (DV)',
            'description' => 'Any payment out of the city treasury.',
            'turnaround_days' => 10,
            'steps' => [
                $this->office('CA'),
                // Required to optional.
                $this->office('CBO', optional: true),
                $this->office('TREA'),
                $this->office('BAC', 'Procurement review'),
                $this->office('ACC', 'BAC committee member', optional: true, checked: true),
                // Optional to required.
                $this->office('PDC', 'BAC committee member'),
                // CR removed; CENRO now starts unticked.
                $this->office('CENRO', 'BAC committee member', optional: true),
                $this->office('ASSO', 'BAC committee member', optional: true, checked: true),
                // GSO moved before the City Mayor.
                $this->office('GSO'),
                $this->office('OCM'),
                $this->office('ACC'),
                $this->office('TREA', 'Final release'),
                // Added.
                $this->office('ARO', 'Filing'),
            ],
        ])->assertSessionHasNoErrors()->assertRedirect(route('super-admin.document-types.index'));

        $dv = $this->type('DV');
        $this->assertSame('Disbursement Voucher (DV)', $dv->name);
        $this->assertSame(10, $dv->turnaround_days);
        $this->assertSame('DV', $dv->code);
        $this->assertFalse($dv->is_confidential);
        $this->assertNotNull($dv->customized_at);
        $this->assertNotNull($dv->route_customized_at);

        $template = $this->templates($clerk)['DV'];
        $this->assertCount(13, $template['steps']);
        $this->assertSame([true, false], [$template['steps'][1]['optional'], $template['steps'][1]['checked']]);
        $this->assertSame('Procurement review', $template['steps'][3]['purpose']);
        $this->assertSame([false, false], [$template['steps'][5]['optional'], $template['steps'][5]['checked']]);
        $this->assertSame([true, false], [$template['steps'][6]['optional'], $template['steps'][6]['checked']]);
        $this->assertSame($this->officeId('GSO'), $template['steps'][8]['office_id']);
        $this->assertSame($this->officeId('ARO'), $template['steps'][12]['office_id']);

        // The new route, filed: CBO and CENRO left unticked.
        $route = $this->automaticRoute($clerk, 'DV');
        $this->assertSame(
            array_map($this->officeId(...), ['CICTO', 'CA', 'TREA', 'BAC', 'ACC', 'PDC', 'ASSO', 'GSO', 'OCM', 'ACC', 'TREA', 'ARO']),
            $route,
        );

        $this->submit($clerk, 'DV', $route)->assertSessionHasNoErrors();
        $document = Document::query()->latest('id')->firstOrFail();

        $this->assertSame(array_slice($route, 1), $document->routeStops()->pluck('office_id')->all());
        $this->assertSame('2026-10-15 18:00:00', $document->due_at->format('Y-m-d H:i:s'), 'The new turnaround sets the deadline.');

        // The renamed type everywhere its name is read.
        $this->actingAs($clerk)
            ->get(route('documents.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('documentTypes', fn ($types) => collect($types)->firstWhere('id', $dv->id)['name'] === 'Disbursement Voucher (DV)'));

        // The document filed before the edit kept its route.
        $this->assertSame($earlierStops, $earlier->routeStops()->get(['position', 'office_id', 'status'])->toArray());

        $this->assertSame(1, $this->changes());
        $this->assertSame(
            'Document type "Disbursement Voucher (DV)" (DV) changed: name, description, turnaround days, route.',
            SecurityEvent::query()->latest('id')->value('summary'),
        );

        Carbon::setTestNow();
    }

    public function test_the_page_shows_what_was_saved_when_it_is_opened_again(): void
    {
        $payload = $this->payload('DEMOLITION-ORDER');

        // SP "only if ordinance-based" made required; ENGR made optional.
        $payload['steps'][3]['optional'] = false;
        $payload['steps'][1]['optional'] = true;
        $payload['steps'][1]['checked'] = true;
        $payload['steps'][1]['purpose'] = 'Structural assessment, if a structure stands';

        $this->save('DEMOLITION-ORDER', $payload)->assertSessionHasNoErrors();

        $row = $this->row('DEMOLITION-ORDER');
        $this->assertTrue($row['customized']);
        $this->assertTrue($row['route_customized']);
        $this->assertFalse($row['steps'][3]['optional']);
        $this->assertSame([true, true, 'Structural assessment, if a structure stands'], [$row['steps'][1]['optional'], $row['steps'][1]['checked'], $row['steps'][1]['purpose']]);

        // And saving that again changes nothing.
        $this->save('DEMOLITION-ORDER', $this->payload('DEMOLITION-ORDER'))->assertSessionHasNoErrors();
        $this->assertSame(1, $this->changes());
    }

    public function test_a_step_chosen_when_filing_can_be_moved_reworded_and_removed(): void
    {
        $row = $this->row('PERMIT');
        [$choice, $mayor, $same] = $row['steps'];

        // An office ahead of the choice, both reworded: the "same office"
        // step follows the choice to its new place.
        $this->save('PERMIT', ['name' => 'Permit', 'steps' => [
            $this->office('ARO', 'Logs it'),
            ['kind' => 'choose', 'step_id' => $choice['id'], 'optional' => false, 'purpose' => 'Regulating office — evaluates'],
            $this->office('OCM', 'Approval'),
            ['kind' => 'same', 'step_id' => $same['id'], 'purpose' => 'Regulating office — releases'],
        ]])->assertSessionHasNoErrors();

        $template = $this->templates()['PERMIT'];
        $this->assertSame(['office', 'choose', 'office', 'same'], array_column($template['steps'], 'kind'));
        $this->assertSame('Regulating office — evaluates', $template['steps'][1]['purpose']);
        $this->assertSame('origin', $template['steps'][1]['suggested'], 'The choice keeps what it offers.');
        $this->assertSame(1, $template['steps'][3]['step'], 'The same office as the choice, now at index 1.');
        $this->assertSame('Regulating office — releases', $template['steps'][3]['purpose']);

        // Both removed: plain offices.
        $this->save('PERMIT', ['name' => 'Permit', 'steps' => [
            $this->office('BPLO'),
            $this->office('OCM', 'Approval'),
            $this->office('BPLO', 'Release'),
        ]])->assertSessionHasNoErrors();

        $this->assertSame(['office', 'office', 'office'], array_column($this->templates()['PERMIT']['steps'], 'kind'));
        $this->assertSame(0, DocumentTypeRouteStep::query()->whereKey([$choice['id'], $same['id']])->count());
    }

    public function test_steps_that_are_not_offices_are_kept_never_invented(): void
    {
        $row = $this->row('PERMIT');
        [$choice, , $same] = $row['steps'];
        $before = $this->savedRoute('PERMIT');

        $cases = [
            // The repeat before the choice it repeats.
            [[['kind' => 'same', 'step_id' => $same['id']], ['kind' => 'choose', 'step_id' => $choice['id'], 'purpose' => 'Office']], 'steps.0.step_id', 'so it has to come after it'],
            // The repeat without its choice.
            [[$this->office('OCM'), ['kind' => 'same', 'step_id' => $same['id']]], 'steps.1.step_id', 'Keep that step, or remove this one too.'],
            // One step twice.
            [[['kind' => 'choose', 'step_id' => $choice['id'], 'purpose' => 'A'], $this->office('OCM'), ['kind' => 'choose', 'step_id' => $choice['id'], 'purpose' => 'B']], 'steps.2.step_id', 'on the route twice'],
            // A step of another type.
            [[['kind' => 'choose', 'step_id' => $this->row('MEMO')['steps'][0]['id'], 'purpose' => 'Concerned office']], 'steps.0.step_id', 'not one the saved route has'],
            // A step passed off as another kind.
            [[['kind' => 'note', 'step_id' => $choice['id'], 'purpose' => 'Hello']], 'steps.0.step_id', 'not one the saved route has'],
            // A made-up one.
            [[['kind' => 'choose', 'purpose' => 'Any office']], 'steps.0.step_id', 'not one the saved route has'],
            // A choice with no label.
            [[['kind' => 'choose', 'step_id' => $choice['id'], 'purpose' => '  ']], 'steps.0.purpose', 'its only label'],
            [[['kind' => 'teleport', 'step_id' => $choice['id']]], 'steps.0.kind', 'not a kind of step'],
        ];

        foreach ($cases as [$steps, $key, $message]) {
            $this->save('PERMIT', ['name' => 'Permit', 'steps' => $steps])->assertSessionHasErrors($key);
            $this->assertStringContainsString($message, session('errors')->first($key), $key);
        }

        $this->assertSame($before, $this->savedRoute('PERMIT'));
        $this->assertSame(0, $this->changes());
    }

    public function test_a_note_is_not_a_stop_and_does_not_keep_an_office_from_itself(): void
    {
        $note = collect($this->row('EXEC-ORDER')['steps'])->firstWhere('kind', 'note');
        $noteStep = ['kind' => 'note', 'step_id' => $note['id'], 'purpose' => $note['purpose']];

        $this->save('EXEC-ORDER', ['name' => 'Executive Order', 'steps' => [$noteStep]])
            ->assertSessionHasErrors(['steps' => 'Add at least one office to the route.']);

        $this->save('EXEC-ORDER', ['name' => 'Executive Order', 'steps' => [$this->office('SP'), $noteStep, $this->office('SP')]])
            ->assertSessionHasErrors(['steps' => 'The same office is listed twice in a row. An office can come round again, but not straight after itself.']);

        // Reworded, and moved to the end.
        $this->save('EXEC-ORDER', ['name' => 'Executive Order', 'steps' => [
            $this->office('OCM'), $this->office('CLO'), $this->office('ARO'),
            ['kind' => 'note', 'step_id' => $note['id'], 'purpose' => 'Press Broadcast once it is filed.'],
        ]])->assertSessionHasNoErrors();

        $template = $this->templates()['EXEC-ORDER'];
        $this->assertSame(['kind' => 'note', 'purpose' => 'Press Broadcast once it is filed.'], $template['steps'][3]);
        $this->assertTrue($template['broadcast'], 'Broadcast is the type\'s, not the note\'s.');
    }

    public function test_the_same_rules_as_any_route_still_hold(): void
    {
        $base = ['name' => 'Payroll'];

        $this->save('PAYROLL', $base + ['steps' => []])->assertSessionHasErrors(['steps' => 'Add at least one office to the route.']);
        $this->save('PAYROLL', $base + ['steps' => [$this->office('HRMO'), $this->office('HRMO')]])->assertSessionHasErrors('steps');
        $this->save('PAYROLL', $base + ['turnaround_days' => 366, 'steps' => [$this->office('HRMO')]])->assertSessionHasErrors('turnaround_days');
        $this->save('PAYROLL', ['name' => 'Purchase Order', 'steps' => [$this->office('HRMO')]])->assertSessionHasErrors(['name' => 'Another document type already has that name.']);
        $this->save('PAYROLL', $base + ['code' => 'PAY', 'steps' => [$this->office('HRMO')]])->assertSessionHasErrors(['code' => 'The code cannot be changed after the type is created.']);

        Office::query()->where('code', 'CBO')->update(['is_active' => false]);
        $this->save('PAYROLL', $this->payload('PAYROLL'))->assertSessionHasErrors(['steps.1.office_id' => 'An office on the route is no longer active. Remove it or choose another.']);

        // Confidential and Broadcast are not the page's, whatever is posted.
        $this->save('PAYROLL', $base + ['is_confidential' => true, 'allows_broadcast' => true, 'is_custom' => true, 'steps' => [$this->office('HRMO')]])->assertSessionHasNoErrors();
        $payroll = $this->type('PAYROLL');
        $this->assertFalse($payroll->is_confidential);
        $this->assertFalse($payroll->allows_broadcast);
        $this->assertFalse($payroll->is_custom);
    }

    public function test_a_confidential_type_s_route_cannot_be_changed_but_the_rest_can(): void
    {
        $before = $this->savedRoute('CONFIDENTIAL');

        $this->save('CONFIDENTIAL', ['name' => 'Confidential', 'steps' => [$this->office('CICTO')]])
            ->assertSessionHasErrors('steps');
        $this->assertSame($before, $this->savedRoute('CONFIDENTIAL'));

        $this->save('CONFIDENTIAL', ['name' => 'Confidential (Mayor / HRMO)', 'turnaround_days' => 2])->assertSessionHasNoErrors();

        $type = $this->type('CONFIDENTIAL');
        $this->assertSame('Confidential (Mayor / HRMO)', $type->name);
        $this->assertSame(2, $type->turnaround_days);
        $this->assertTrue($type->is_confidential);
        $this->assertSame($before, $this->savedRoute('CONFIDENTIAL'));
        $this->assertNull($type->route_customized_at);

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.document-types.restore-route', $type))
            ->assertForbidden();
    }

    // ── Deactivating ─────────────────────────────────────────────────────

    public function test_deactivating_a_built_in_type_hides_it_from_submit_document_only(): void
    {
        $clerk = $this->clerk('GSO');
        $route = $this->automaticRoute($clerk, 'PO');
        $this->submit($clerk, 'PO', $route)->assertSessionHasNoErrors();
        $document = Document::query()->firstOrFail();
        $po = $this->type('PO');

        $this->actingAs($this->superAdmin)
            ->patch(route('super-admin.document-types.status', $po), ['is_active' => false])
            ->assertSessionHasNoErrors();

        $this->assertFalse($po->refresh()->is_active);

        $this->actingAs($clerk)
            ->get(route('documents.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('documentTypes', fn ($types) => ! collect($types)->contains('id', $po->id))
                ->missing('routeTemplates.'.$po->id));

        $this->submit($clerk, 'PO', $route)->assertSessionHasErrors('document_type_id');

        $this->actingAs($clerk)
            ->get(route('documents.index', ['document_type_id' => $po->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('documents.data', 1)
                ->where('documents.data.0.document_type', 'Purchase Order'));

        $this->actingAs($this->superAdmin)
            ->getJson(route('reports.activity.documents'))
            ->assertJsonPath('data.0.id', $document->id)
            ->assertJsonPath('data.0.type', 'Purchase Order');

        // A deploy does not switch it back on.
        $this->seed(DocumentTypeSeeder::class);
        $this->assertFalse($po->refresh()->is_active);

        $this->actingAs($this->superAdmin)
            ->patch(route('super-admin.document-types.status', $po), ['is_active' => true])
            ->assertSessionHasNoErrors();

        $this->assertArrayHasKey('PO', $this->templates($clerk));
        $this->submit($clerk, 'PO', $route)->assertSessionHasNoErrors();
    }

    // ── Restoring ────────────────────────────────────────────────────────

    public function test_a_changed_route_can_be_put_back_the_way_it_came(): void
    {
        $original = $this->savedRoute('DV');
        $template = $this->templates()['DV'];

        $this->save('DV', ['name' => 'Disbursement Voucher', 'steps' => [$this->office('CA'), $this->office('TREA')]])->assertSessionHasNoErrors();
        $this->assertTrue($this->row('DV')['route_customized']);

        $this->actingAs($this->superAdmin)
            ->from(route('super-admin.document-types.index'))
            ->post(route('super-admin.document-types.restore-route', $this->type('DV')))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('toast', fn (array $toast) => str_contains($toast['message'], 'has its original route again'));

        $this->assertSame($original, $this->savedRoute('DV'));
        $this->assertSame($template, $this->templates()['DV']);
        $this->assertNull($this->type('DV')->route_customized_at);
        $this->assertSame('Document type "Disbursement Voucher" (DV) route restored to the original.', SecurityEvent::query()->latest('id')->value('summary'));

        // Again: nothing to do, nothing logged.
        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.document-types.restore-route', $this->type('DV')))
            ->assertSessionHas('toast', fn (array $toast) => str_contains($toast['message'], 'already has its original route'));
        $this->assertSame(2, $this->changes());
    }

    // ── The seeder ───────────────────────────────────────────────────────

    public function test_a_deploy_keeps_what_a_super_admin_changed_and_keeps_the_rest_current(): void
    {
        $this->save('DV', ['name' => 'Voucher', 'turnaround_days' => 7, 'steps' => [$this->office('CA'), $this->office('TREA')]])->assertSessionHasNoErrors();
        $dvRoute = $this->savedRoute('DV');

        // Renamed only: its route is still the system's.
        $this->save('PAYROLL', ['name' => 'Payroll (regular)'] + array_intersect_key($this->payload('PAYROLL'), ['steps' => true]))->assertSessionHasNoErrors();
        $this->assertNull($this->type('PAYROLL')->route_customized_at);

        // A type nobody changed, drifted as if an older release had seeded
        // it: the next deploy brings it in line.
        $memoRoute = $this->savedRoute('MEMO');
        $payrollRoute = $this->savedRoute('PAYROLL');
        DocumentType::query()->where('code', 'MEMO')->update(['name' => 'Memo (old)', 'turnaround_days' => 9]);
        $this->type('MEMO')->routeSteps()->delete();
        $this->type('PAYROLL')->routeSteps()->delete();

        $this->seed(DocumentTypeSeeder::class);
        $this->seed(DocumentTypeSeeder::class);

        $dv = $this->type('DV');
        $this->assertSame(['Voucher', 7], [$dv->name, $dv->turnaround_days]);
        $this->assertSame($dvRoute, $this->savedRoute('DV'));

        $this->assertSame('Payroll (regular)', $this->type('PAYROLL')->name);
        $this->assertSame($payrollRoute, $this->savedRoute('PAYROLL'), 'Its route came back from the original.');

        $memo = $this->type('MEMO');
        $this->assertSame(['Memorandum', null], [$memo->name, $memo->turnaround_days]);
        $this->assertSame($memoRoute, $this->savedRoute('MEMO'));

        $this->assertSame(count(DocumentTypeSeeder::BUILT_IN_TYPES), DocumentType::query()->where('is_custom', false)->where('is_active', true)->count());
    }

    // ── Who may ──────────────────────────────────────────────────────────

    public function test_only_a_super_admin_can_change_a_built_in_type(): void
    {
        $office = Office::query()->where('code', 'BPLO')->firstOrFail();
        $dv = $this->type('DV');
        $payload = $this->payload('DV');
        $route = $this->savedRoute('DV');

        foreach ([$this->admin($office), $this->staff($office)] as $user) {
            $this->save('DV', ['name' => 'Taken'] + $payload, $user)->assertForbidden();
            $this->actingAs($user)->patch(route('super-admin.document-types.status', $dv), ['is_active' => false])->assertForbidden();
            $this->actingAs($user)->post(route('super-admin.document-types.restore-route', $dv))->assertForbidden();
        }

        $dv->refresh();
        $this->assertSame('Disbursement Voucher', $dv->name);
        $this->assertTrue($dv->is_active);
        $this->assertSame($route, $this->savedRoute('DV'));
        $this->assertSame(0, $this->changes());
    }

    public function test_a_retired_placeholder_type_cannot_be_changed(): void
    {
        $ord = DocumentType::query()->create(['code' => 'ORD', 'name' => 'Ordinance / Resolution', 'is_active' => false]);

        $this->assertFalse($this->row('ORD')['editable']);

        $this->actingAs($this->superAdmin)
            ->patch(route('super-admin.document-types.update', $ord), ['name' => 'Ordinance', 'steps' => [$this->office('SP')]])
            ->assertForbidden();
        $this->actingAs($this->superAdmin)
            ->patch(route('super-admin.document-types.status', $ord), ['is_active' => true])
            ->assertForbidden();
        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.document-types.restore-route', $ord))
            ->assertForbidden();

        $this->assertFalse($ord->refresh()->is_active);
    }

    public function test_a_custom_type_still_passes_through_offices_only(): void
    {
        $choice = $this->row('MEMO')['steps'][0];

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.document-types.store'), [
                'name' => 'Special Event Permit',
                'code' => 'EVENT-PERMIT',
                'steps' => [['kind' => 'choose', 'step_id' => $choice['id'], 'purpose' => 'Any office']],
            ])
            ->assertSessionHasErrors(['steps.0.kind' => 'A type you add can only pass through offices.']);

        // "Starts ticked" is a built-in type's.
        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.document-types.store'), [
                'name' => 'Special Event Permit',
                'code' => 'EVENT-PERMIT',
                'steps' => [$this->office('BPLO', optional: true, checked: true), $this->office('OCM')],
            ])
            ->assertSessionHasNoErrors();

        $type = $this->type('EVENT-PERMIT');
        $this->assertSame([RouteStepKind::Office, RouteStepKind::Office], $type->routeSteps()->get()->pluck('kind')->all());
        $this->assertFalse($type->routeSteps()->firstOrFail()->is_checked);
        $this->assertNull($type->customized_at);

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.document-types.restore-route', $type))
            ->assertForbidden();
    }
}
