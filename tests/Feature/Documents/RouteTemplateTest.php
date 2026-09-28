<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\RegisterDocument;
use App\Enums\DocumentPriority;
use App\Enums\DocumentStatus;
use App\Enums\MovementAction;
use App\Enums\RouteStopStatus;
use App\Models\Document;
use App\Models\DocumentRouteStop;
use App\Models\DocumentType;
use App\Models\Office;
use App\Models\User;
use App\Support\RoutePlan;
use App\Support\RouteTemplates;
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
 * The suggested route per document type (client request, 2026-09-25), from
 * the client's DTS_Office_Routing_Paths.pdf: pick "Disbursement Voucher" and
 * the route fills itself in -- City Admin, Budget, Treasury, BAC, Mayor, GSO,
 * Accounting, Treasury.
 */
class RouteTemplateTest extends TestCase
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

    private function type(string $code): DocumentType
    {
        return DocumentType::query()->where('code', $code)->firstOrFail();
    }

    private function adminAt(string $code): User
    {
        $office = Office::query()->where('code', $code)->firstOrFail();

        return User::query()->where('office_id', $office->id)->where('role', 'admin')->first()
            ?? $this->admin($office);
    }

    /**
     * @param  list<string>  $codes
     */
    private function submit(User $user, string $type, array $codes): TestResponse
    {
        Storage::fake('documents');

        return $this->actingAs($user)->post(route('documents.store'), [
            'title' => 'Voucher for office supplies',
            'document_type_id' => $this->type($type)->id,
            'office_ids' => array_map(fn (string $code) => $this->officeId($code), $codes),
            'priority' => 'normal',
            'file' => UploadedFile::fake()->create('voucher.pdf', 40, 'application/pdf'),
        ]);
    }

    private function receive(Document $document): void
    {
        $holder = Office::query()->findOrFail($document->refresh()->openMovement->to_office_id);

        $this->actingAs($this->adminAt($holder->code))
            ->post(route('documents.transitions.store', $document), [
                'action' => MovementAction::Received->value,
                'expected_movement_id' => $document->openMovement->id,
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_every_document_type_has_a_route_and_every_office_it_names_exists(): void
    {
        $this->assertEqualsCanonicalizing(
            DocumentType::query()->where('is_active', true)->pluck('code')->all(),
            RouteTemplates::typeCodes(),
            'A type on the Submit form without a suggested route, or a route for a type that is not there.',
        );

        $this->assertSame(
            [],
            array_values(array_diff(RouteTemplates::codes(), Office::query()->where('is_active', true)->pluck('code')->all())),
            'A template names an office the seeded list does not have.',
        );
    }

    public function test_the_form_is_sent_each_type_s_route_resolved_to_offices(): void
    {
        $dv = $this->type('DV');

        $this->actingAs($this->staff(Office::query()->where('code', 'CICTO')->firstOrFail()))
            ->get(route('documents.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('routeTemplates.'.$dv->id.'.steps', 13)
                ->where('routeTemplates.'.$dv->id.'.steps.0.office_id', $this->officeId('CA'))
                ->where('routeTemplates.'.$dv->id.'.steps.2.office_id', $this->officeId('TREA'))
                ->where('routeTemplates.'.$dv->id.'.steps.3.office_id', $this->officeId('BAC'))
                // The committee's members, each a stop, in the PDF's order --
                // on the route unless the sender unticks one.
                ->where('routeTemplates.'.$dv->id.'.steps.4.office_id', $this->officeId('ACC'))
                ->where('routeTemplates.'.$dv->id.'.steps.5.office_id', $this->officeId('PDC'))
                ->where('routeTemplates.'.$dv->id.'.steps.6.office_id', $this->officeId('CR'))
                ->where('routeTemplates.'.$dv->id.'.steps.7.office_id', $this->officeId('CENRO'))
                ->where('routeTemplates.'.$dv->id.'.steps.8.office_id', $this->officeId('ASSO'))
                ->where('routeTemplates.'.$dv->id.'.steps.8.purpose', 'BAC committee member')
                ->where('routeTemplates.'.$dv->id.'.steps.8.optional', true)
                ->where('routeTemplates.'.$dv->id.'.steps.8.checked', true)
                ->where('routeTemplates.'.$dv->id.'.steps.9.office_id', $this->officeId('OCM'))
                // Treasury again, last: the voucher's final release.
                ->where('routeTemplates.'.$dv->id.'.steps.12.office_id', $this->officeId('TREA'))
                ->where('routeTemplates.'.$dv->id.'.confidential', false)
                ->where('routeTemplates.'.$this->type('CONFIDENTIAL')->id.'.confidential', true)
                ->where('routeTemplates.'.$this->type('EXEC-ORDER')->id.'.broadcast', true)
                ->where('routeTemplates.'.$this->type('MEMO-CIRCULAR')->id.'.broadcast', true)
                // A conditional step starts unticked, and says so.
                ->where('routeTemplates.'.$this->type('DEMOLITION-ORDER')->id.'.steps.3.optional', true)
                ->where('routeTemplates.'.$this->type('DEMOLITION-ORDER')->id.'.steps.3.checked', false)
                // A sender-chosen step, limited to two offices.
                ->where('routeTemplates.'.$this->type('CONFIDENTIAL')->id.'.steps.0.kind', 'choose')
                ->where('routeTemplates.'.$this->type('CONFIDENTIAL')->id.'.steps.0.only', [$this->officeId('OCM'), $this->officeId('HRMO')])
                ->where('routeTemplates.'.$this->type('CONFIDENTIAL')->id.'.steps.0.suggested', $this->officeId('OCM'))
                // "Concerned regulating office" suggests the filer's own.
                ->where('routeTemplates.'.$this->type('PERMIT')->id.'.steps.0.suggested', 'origin')
                ->where('routeTemplates.'.$this->type('PERMIT')->id.'.steps.2.kind', 'same')
                // The broadcast is said, not performed.
                ->where('routeTemplates.'.$this->type('EXEC-ORDER')->id.'.steps.3.kind', 'note'));
    }

    public function test_an_office_the_installation_has_deactivated_is_named_and_left_out(): void
    {
        Office::query()->where('code', 'BFP')->update(['is_active' => false]);
        $permit = $this->type('BUSINESS-PERMIT');

        $this->actingAs($this->staff(Office::query()->where('code', 'BPLO')->firstOrFail()))
            ->get(route('documents.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('routeTemplates.'.$permit->id.'.steps.2.office_id', null)
                ->where('routeTemplates.'.$permit->id.'.steps.2.missing_office', 'Bureau of Fire Protection')
                ->where('routeTemplates.'.$permit->id.'.steps.1.missing_office', null));
    }

    public function test_a_type_with_no_route_is_simply_not_suggested(): void
    {
        $new = DocumentType::factory()->create(['code' => 'SOMETHING-NEW', 'is_active' => true]);

        $this->actingAs($this->staff(Office::query()->where('code', 'BPLO')->firstOrFail()))
            ->get(route('documents.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->missing('routeTemplates.'.$new->id)
                ->has('routeTemplates.'.$this->type('DV')->id));
    }

    /**
     * The client's own example, end to end: a voucher filed by a requesting
     * office goes round the whole route -- Treasury twice, the BAC's members
     * one after another, CENRO itself again as a member -- and finishes at the
     * second Treasury.
     */
    public function test_a_disbursement_voucher_visits_treasury_twice_and_finishes_there(): void
    {
        $clerk = $this->staff(Office::query()->where('code', 'CENRO')->firstOrFail());

        $this->submit($clerk, 'DV', ['CENRO', 'CA', 'CBO', 'TREA', 'BAC', 'ACC', 'PDC', 'CR', 'CENRO', 'ASSO', 'OCM', 'GSO', 'ACC', 'TREA'])
            ->assertSessionHasNoErrors()
            // The confirmation reads the route back, Treasury both times.
            ->assertSessionHas('upload', fn (array $upload) => str_ends_with(
                $upload['message'],
                'Bids and Awards Committee, Office of the City Accountant, Office of the City Planning and Development Coordinator, Office of the City Civil Registrar, Office of the City Environmental and Natural Resources Officer, Office of the City Assessor, Office of the City Mayor, Office of the City Mayor - General Services Office, Office of the City Accountant and Office of the City Treasurer.',
            ));

        $document = Document::query()->firstOrFail();
        $treasury = $this->officeId('TREA');

        $this->assertSame(
            array_map(fn (string $code) => $this->officeId($code), array_slice(['CENRO', 'CA', 'CBO', 'TREA', 'BAC', 'ACC', 'PDC', 'CR', 'CENRO', 'ASSO', 'OCM', 'GSO', 'ACC', 'TREA'], 1)),
            $document->routeStops()->pluck('office_id')->all(),
        );

        // CENRO receives and it sets off; every receipt moves it on.
        $visited = [];

        for ($hop = 0; $hop < 20; $hop++) {
            $visited[] = $document->refresh()->openMovement->to_office_id;
            $this->receive($document);

            if ($document->refresh()->status === DocumentStatus::Completed) {
                break;
            }
        }

        $this->assertSame(
            array_map(fn (string $code) => $this->officeId($code), ['CENRO', 'CA', 'CBO', 'TREA', 'BAC', 'ACC', 'PDC', 'CR', 'CENRO', 'ASSO', 'OCM', 'GSO', 'ACC', 'TREA']),
            $visited,
        );
        $this->assertSame(DocumentStatus::Completed, $document->status, 'The second Treasury receipt finishes it.');
        $this->assertSame($treasury, $document->lastMovement->to_office_id);
        $this->assertTrue($document->routeStops()->get()->every(
            fn (DocumentRouteStop $stop) => $stop->status === RouteStopStatus::Visited,
        ));
    }

    /**
     * The first Treasury visit must not look like the end of the line: the
     * last-route-office rules (Send hidden, Complete) key off "the last
     * visited stop", and Treasury is also the last stop on the plan.
     */
    public function test_the_first_treasury_visit_is_not_mistaken_for_the_last_stop(): void
    {
        $clerk = $this->staff(Office::query()->where('code', 'CENRO')->firstOrFail());
        $this->submit($clerk, 'DV', ['CENRO', 'CA', 'CBO', 'TREA', 'BAC', 'OCM', 'GSO', 'ACC', 'TREA'])->assertSessionHasNoErrors();
        $document = Document::query()->firstOrFail();

        // CENRO, CA, CBO receive: the folder is at Treasury the first time.
        foreach (range(1, 3) as $hop) {
            $this->receive($document);
        }

        $this->assertSame($this->officeId('TREA'), $document->refresh()->openMovement->to_office_id);
        $this->assertFalse($document->isAtLastRouteStop());

        $this->receive($document);

        $this->assertSame(DocumentStatus::UnderReview, $document->refresh()->status, 'Still travelling.');
        $this->assertSame($this->officeId('BAC'), $document->openMovement->to_office_id);
    }

    /**
     * BPLO filing a Business Permit: the template's "BPLO -- receives the
     * application" is where the folder already is. The form merges the two;
     * a request that does not is refused rather than sending the folder to
     * its own desk.
     */
    public function test_the_same_office_twice_in_a_row_is_refused_but_a_return_later_is_not(): void
    {
        $bplo = $this->staff(Office::query()->where('code', 'BPLO')->firstOrFail());

        $this->submit($bplo, 'BUSINESS-PERMIT', ['BPLO', 'BPLO', 'PDC', 'BFP', 'ENGR', 'TREA', 'OCM', 'BPLO'])
            ->assertSessionHasErrors(['office_ids' => 'The same department is listed twice in a row. A department can come round again, but not straight after itself.']);

        $this->assertSame(0, Document::query()->count());

        // Merged, it is accepted -- and it comes back to BPLO for release.
        $this->submit($bplo, 'BUSINESS-PERMIT', ['BPLO', 'PDC', 'BFP', 'ENGR', 'TREA', 'OCM', 'BPLO'])
            ->assertSessionHasNoErrors();

        $document = Document::query()->firstOrFail();

        $this->assertSame(
            array_map(fn (string $code) => $this->officeId($code), ['PDC', 'BFP', 'ENGR', 'TREA', 'OCM', 'BPLO']),
            $document->routeStops()->pluck('office_id')->all(),
        );
    }

    public function test_the_first_office_is_still_the_filer_s_own(): void
    {
        $clerk = $this->staff(Office::query()->where('code', 'CENRO')->firstOrFail());

        // A route that starts at City Admin, as if the template's first office
        // were the filer: refused, the originating-office rule is unchanged.
        $this->submit($clerk, 'DV', ['CA', 'CBO', 'TREA'])
            ->assertSessionHasErrors(['office_ids' => 'The first department must be your own office, because the document is registered under it.']);
    }

    public function test_a_route_longer_than_every_office_twice_is_refused(): void
    {
        $offices = Office::query()->where('is_active', true)->pluck('id');
        $ownOffice = Office::query()->where('code', 'BPLO')->firstOrFail();
        $other = $offices->first(fn (int $id) => $id !== $ownOffice->id);

        // Own office, other, own, other, ... one past the ceiling.
        $route = [];

        for ($i = 0; $i <= RoutePlan::maxStops(); $i++) {
            $route[] = $i % 2 === 0 ? $ownOffice->id : $other;
        }

        Storage::fake('documents');

        $this->actingAs($this->staff($ownOffice))->post(route('documents.store'), [
            'title' => 'Too long',
            'document_type_id' => $this->type('MEMO')->id,
            'office_ids' => $route,
            'priority' => 'normal',
            'file' => UploadedFile::fake()->create('memo.pdf', 40, 'application/pdf'),
        ])->assertSessionHasErrors('office_ids');

        $this->assertSame(2 * $offices->count(), RoutePlan::maxStops());
    }

    public function test_registering_directly_merges_a_first_stop_that_is_the_filing_office(): void
    {
        $bplo = Office::query()->where('code', 'BPLO')->firstOrFail();

        $document = app(RegisterDocument::class)->handle(
            title: 'Business permit',
            documentTypeId: $this->type('BUSINESS-PERMIT')->id,
            priority: DocumentPriority::Normal,
            originatingOffice: $bplo,
            creator: $this->staff($bplo),
            routeOfficeIds: [$bplo->id, $this->officeId('PDC'), $this->officeId('PDC'), $this->officeId('OCM'), $bplo->id],
        );

        $this->assertSame(
            [$this->officeId('PDC'), $this->officeId('OCM'), $bplo->id],
            $document->routeStops()->pluck('office_id')->all(),
        );
    }

    public function test_collapse_merges_only_neighbours(): void
    {
        $this->assertSame([1, 2, 1, 3], RoutePlan::collapse([1, 1, 2, 2, 2, 1, 3, 3]));
        $this->assertSame([], RoutePlan::collapse([]));
        $this->assertSame([5], RoutePlan::collapse([5, 5]));
    }

    /** Send to Another Office is not the Submit form: a re-route still names each office once. */
    public function test_a_mid_way_re_route_still_refuses_an_office_twice(): void
    {
        $bplo = Office::query()->where('code', 'BPLO')->firstOrFail();
        $document = $this->registerDocument($bplo, $this->staff($bplo));

        $this->actingAs($this->adminAt('BPLO'))
            ->post(route('documents.transitions.store', $document), [
                'action' => 'forwarded',
                'to_office_ids' => [$this->officeId('TREA'), $this->officeId('OCM'), $this->officeId('TREA')],
                'expected_movement_id' => $document->openMovement->id,
            ])
            ->assertSessionHasErrors('to_office_ids');
    }
}
