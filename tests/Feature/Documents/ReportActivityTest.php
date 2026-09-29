<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\RegisterDocument;
use App\Actions\Documents\TransitionDocument;
use App\Enums\DocumentPriority;
use App\Enums\MovementAction;
use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\DocumentType;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * §19 "User activity", by document and by person (client request,
 * 2026-09-25): folded rows on the Reports page, each opening onto a trail.
 */
class ReportActivityTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    private Office $mpdo;

    private Office $mto;

    private User $clerk;

    private User $mpdoAdmin;

    private User $mtoAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mpdo = $this->office('MPDO', 'Planning Office');
        $this->mto = $this->office('MTO', 'Treasury');
        $this->clerk = $this->staff($this->mpdo);
        $this->mpdoAdmin = $this->admin($this->mpdo);
        $this->mtoAdmin = $this->admin($this->mto);
    }

    private function file(string $title, ?string $type = null, ?Office $office = null): Document
    {
        $office ??= $this->mpdo;

        return app(RegisterDocument::class)->handle(
            title: $title,
            documentTypeId: ($type === null
                ? $this->documentType()
                : DocumentType::factory()->create(['name' => $type]))->id,
            priority: DocumentPriority::Normal,
            originatingOffice: $office,
            creator: $office->is($this->mpdo) ? $this->clerk : $this->staff($office),
            remarks: 'Private remark that belongs to the document',
        );
    }

    private function act(Document $document, MovementAction $action, User $actor, ?Office $to = null, ?string $remarks = null): void
    {
        app(TransitionDocument::class)->handle(
            document: $document->refresh(),
            action: $action,
            actor: $actor,
            remarks: $remarks,
            toOfficeId: $to?->id,
            expectedMovementId: $document->refresh()->openMovement?->id,
        );
    }

    public function test_documents_are_listed_by_latest_activity_with_their_type_and_date(): void
    {
        Carbon::setTestNow('2026-09-20 09:00:00');
        $affidavit = $this->file('Non-filing for J. Cruz', 'Affidavit of Non-Filing');

        Carbon::setTestNow('2026-09-22 10:00:00');
        $permit = $this->file('Bakery permit', 'Business Permit');

        Carbon::setTestNow('2026-09-25 14:30:00');
        $this->act($affidavit, MovementAction::Received, $this->mpdoAdmin);

        $this->actingAs($this->mpdoAdmin)
            ->getJson(route('reports.activity.documents', ['months' => 12]))
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.id', $affidavit->id)
            ->assertJsonPath('data.0.type', 'Affidavit of Non-Filing')
            ->assertJsonPath('data.0.control_number', $affidavit->control_number)
            ->assertJsonPath('data.0.actions', 2)
            ->assertJsonPath('data.0.last_activity_at', '2026-09-25T14:30:00+08:00')
            ->assertJsonPath('data.1.id', $permit->id)
            ->assertJsonPath('data.1.actions', 1);
    }

    public function test_a_document_opens_onto_its_whole_trail_with_who_moved_it(): void
    {
        $document = $this->file('Non-filing for J. Cruz', 'Affidavit of Non-Filing');
        $this->act($document, MovementAction::Received, $this->mpdoAdmin);
        $this->act($document, MovementAction::Forwarded, $this->mpdoAdmin, $this->mto, 'Forward remark');
        $this->act($document, MovementAction::Received, $this->mtoAdmin);

        $response = $this->actingAs($this->mpdoAdmin)
            ->getJson(route('reports.activity.document', $document))
            ->assertOk()
            ->assertJsonCount(4, 'steps')
            ->assertJsonPath('steps.0.action', 'registered')
            ->assertJsonPath('steps.0.actor', $this->clerk->name)
            ->assertJsonPath('steps.0.office', 'Planning Office')
            ->assertJsonPath('steps.2.action', 'forwarded')
            ->assertJsonPath('steps.2.actor', $this->mpdoAdmin->name)
            ->assertJsonPath('steps.2.actor_office', 'Planning Office')
            ->assertJsonPath('steps.2.office', 'Treasury')
            ->assertJsonPath('steps.3.actor', $this->mtoAdmin->name);

        // The tracking record, not the document: no remarks ride along --
        // those are behind the Security PIN on the document's own page.
        $this->assertStringNotContainsString('remark', strtolower((string) $response->getContent()));
    }

    public function test_an_office_sees_only_the_activity_on_its_own_documents(): void
    {
        $ours = $this->file('Ours');
        $theirs = $this->file('Theirs', null, $this->mto);

        $ids = collect($this->actingAs($this->mpdoAdmin)
            ->getJson(route('reports.activity.documents'))
            ->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($ours->id));
        $this->assertFalse($ids->contains($theirs->id));

        // 404, not 403: the number's existence is not confirmed either.
        $this->getJson(route('reports.activity.document', $theirs))->assertNotFound();

        // A Super Admin sees both.
        $all = collect($this->actingAs($this->superAdmin())
            ->getJson(route('reports.activity.documents'))
            ->json('data'))->pluck('id');
        $this->assertTrue($all->contains($ours->id) && $all->contains($theirs->id));
    }

    public function test_search_finds_documents_by_type_control_number_or_title(): void
    {
        $affidavit = $this->file('Non-filing for J. Cruz', 'Affidavit of Non-Filing');
        $this->file('Bakery permit', 'Business Permit');

        foreach (['affidavit', 'AFFIDAVIT', $affidavit->control_number, 'j. cruz'] as $term) {
            $this->actingAs($this->mpdoAdmin)
                ->getJson(route('reports.activity.documents', ['q' => $term]))
                ->assertJsonPath('total', 1)
                ->assertJsonPath('data.0.id', $affidavit->id);
        }

        // LIKE metacharacters are text, not wildcards.
        $this->getJson(route('reports.activity.documents', ['q' => '%']))->assertJsonPath('total', 0);
    }

    public function test_the_period_drops_documents_with_no_activity_inside_it(): void
    {
        Carbon::setTestNow('2025-01-10 09:00:00');
        $old = $this->file('Old one');

        Carbon::setTestNow('2026-09-25 09:00:00');
        $new = $this->file('New one');

        $ids = collect($this->actingAs($this->mpdoAdmin)
            ->getJson(route('reports.activity.documents', ['months' => 3]))
            ->json('data'))->pluck('id');

        $this->assertSame([$new->id], $ids->all());

        // ...and a longer period brings it back.
        $this->getJson(route('reports.activity.documents', ['months' => 24]))->assertJsonPath('total', 2);
        $this->assertNotNull($old);
    }

    public function test_the_list_is_ten_to_a_page(): void
    {
        foreach (range(1, 12) as $n) {
            Carbon::setTestNow(Carbon::parse('2026-09-01 08:00:00')->addHours($n));
            $this->file("Document {$n}");
        }

        $this->actingAs($this->mpdoAdmin)
            ->getJson(route('reports.activity.documents'))
            ->assertJsonPath('total', 12)
            ->assertJsonPath('last_page', 2)
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('data.0.title', 'Document 12');

        $this->getJson(route('reports.activity.documents', ['page' => 2]))
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.title', 'Document 1');

        // A page past the end answers with the last page, not an empty one.
        $this->getJson(route('reports.activity.documents', ['page' => 99]))->assertJsonPath('page', 2);
    }

    public function test_people_are_listed_with_their_count_and_open_onto_what_they_did(): void
    {
        $affidavit = $this->file('Non-filing for J. Cruz', 'Affidavit of Non-Filing');
        $permit = $this->file('Bakery permit', 'Business Permit');

        Carbon::setTestNow('2026-09-25 09:00:00');
        $this->act($affidavit, MovementAction::Received, $this->mpdoAdmin);
        Carbon::setTestNow('2026-09-25 11:00:00');
        $this->act($affidavit, MovementAction::Forwarded, $this->mpdoAdmin, $this->mto);
        Carbon::setTestNow('2026-09-25 13:00:00');
        $this->act($permit, MovementAction::Received, $this->mpdoAdmin);

        $people = collect($this->actingAs($this->mpdoAdmin)
            ->getJson(route('reports.activity.users'))
            ->assertOk()
            ->json('data'))->keyBy('name');

        $this->assertSame(3, $people[$this->mpdoAdmin->name]['actions']);
        $this->assertSame('Planning Office', $people[$this->mpdoAdmin->name]['office']);
        $this->assertSame(2, $people[$this->clerk->name]['actions']);

        $this->getJson(route('reports.activity.user', $this->mpdoAdmin))
            ->assertOk()
            ->assertJsonCount(3, 'steps')
            ->assertJsonPath('truncated', false)
            ->assertJsonPath('steps.0.action', 'received')
            ->assertJsonPath('steps.0.document.type', 'Business Permit')
            ->assertJsonPath('steps.1.action', 'forwarded')
            ->assertJsonPath('steps.1.office', 'Treasury')
            ->assertJsonPath('steps.1.document.control_number', $affidavit->control_number);

        // Search by name or by office.
        $this->getJson(route('reports.activity.users', ['q' => strtolower($this->mpdoAdmin->name)]))
            ->assertJsonPath('total', 1);
        $this->getJson(route('reports.activity.users', ['q' => 'planning']))
            ->assertJsonPath('total', 2);
    }

    public function test_a_persons_trail_only_shows_documents_the_viewer_may_see(): void
    {
        $ours = $this->file('Ours');
        $theirs = $this->file('Theirs', null, $this->mto);
        $this->act($ours, MovementAction::Received, $this->mpdoAdmin);
        $this->act($theirs, MovementAction::Received, $this->mtoAdmin);

        // Treasury's admin acted only on Treasury's document, so Planning's
        // admin sees nothing of them.
        $this->actingAs($this->mpdoAdmin)
            ->getJson(route('reports.activity.user', $this->mtoAdmin))
            ->assertJsonCount(0, 'steps');
    }

    public function test_only_admins_and_up_get_the_activity(): void
    {
        $document = $this->file('Anything');

        $this->actingAs($this->clerk);

        foreach ([
            route('reports.activity.documents'),
            route('reports.activity.document', $document),
            route('reports.activity.users'),
            route('reports.activity.user', $this->mpdoAdmin),
        ] as $url) {
            $this->getJson($url)->assertForbidden();
        }

        $this->get(route('reports.index'))
            ->assertInertia(fn (Assert $page) => $page->where('showActivity', false));

        $this->actingAs($this->mpdoAdmin)
            ->get(route('reports.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('showActivity', true)
                ->missing('userActivity'));
    }

    /*
    |--------------------------------------------------------------------------
    | Print and export (client request, 2026-09-29)
    |--------------------------------------------------------------------------
    */

    public function test_the_whole_by_document_list_exports_past_the_first_page(): void
    {
        $documents = [];

        foreach (range(1, 12) as $n) {
            Carbon::setTestNow(Carbon::parse('2026-09-01 08:00:00')->addHours($n));
            $documents[] = $this->file("Document {$n}", 'Business Permit');
        }

        $this->actingAs($this->mpdoAdmin);

        // Every page, not the ten on screen.
        $csv = $this->get(route('reports.activity.export.documents', ['format' => 'csv']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('"Last activity",Type,"Control number",Title,Status,Actions', $csv);
        $this->assertCount(13, array_filter(explode("\n", trim($csv))));

        foreach ($documents as $document) {
            $this->assertStringContainsString($document->control_number, $csv);
        }

        // Newest first, as the list is, with the card's mm/dd/yyyy.
        $this->assertStringContainsString('"09/01/2026 8:00 PM","Business Permit",'.$documents[11]->control_number, $csv);
        $this->assertLessThan(
            strpos($csv, $documents[0]->control_number),
            strpos($csv, $documents[11]->control_number),
        );

        $this->assertStringStartsWith(
            'PK',
            $this->get(route('reports.activity.export.documents', ['format' => 'xlsx']))->streamedContent(),
        );
        $this->assertStringStartsWith(
            '%PDF',
            (string) $this->get(route('reports.activity.export.documents', ['format' => 'pdf']))->getContent(),
        );
    }

    public function test_the_list_export_follows_the_search_and_period_on_screen(): void
    {
        Carbon::setTestNow('2025-01-10 09:00:00');
        $old = $this->file('Old affidavit', 'Affidavit of Non-Filing');

        Carbon::setTestNow('2026-09-25 09:00:00');
        $affidavit = $this->file('Non-filing for J. Cruz', 'Affidavit of Non-Filing');
        $permit = $this->file('Bakery permit', 'Business Permit');

        $csv = $this->actingAs($this->mpdoAdmin)
            ->get(route('reports.activity.export.documents', ['format' => 'csv', 'q' => 'affidavit', 'months' => 3]))
            ->streamedContent();

        $this->assertStringContainsString($affidavit->control_number, $csv);
        $this->assertStringNotContainsString($permit->control_number, $csv);
        $this->assertStringNotContainsString($old->control_number, $csv);
    }

    public function test_the_print_page_says_what_it_covers_and_opens_the_print_dialog(): void
    {
        Carbon::setTestNow('2026-09-25 09:00:00');
        $document = $this->file('Non-filing for J. Cruz', 'Affidavit of Non-Filing');

        $this->actingAs($this->mpdoAdmin)
            ->get(route('reports.activity.export.documents', ['format' => 'print', 'months' => 6, 'q' => 'cruz']))
            ->assertOk()
            ->assertHeader('content-type', 'text/html; charset=UTF-8')
            ->assertSee('User activity by document')
            ->assertSee('Covering: Planning Office')
            ->assertSee('Last 6 months, from 04/01/2026')
            ->assertSee('Matching “cruz”', false)
            ->assertSee($document->control_number)
            ->assertSee('window.print()', false)
            // Nonced, or an enforced CSP blocks the dialog.
            ->assertSee('<script nonce="', false);
    }

    public function test_one_documents_trail_exports_with_where_it_went(): void
    {
        $document = $this->file('Non-filing for J. Cruz', 'Affidavit of Non-Filing');
        $this->act($document, MovementAction::Received, $this->mpdoAdmin);
        $this->act($document, MovementAction::Forwarded, $this->mpdoAdmin, $this->mto, 'Forward remark');
        $this->act($document, MovementAction::Received, $this->mtoAdmin);

        $this->actingAs($this->mpdoAdmin);

        $csv = $this->get(route('reports.activity.export.document', [$document, 'format' => 'csv']))
            ->assertOk()
            ->streamedContent();

        $lines = array_values(array_filter(explode("\n", trim($csv))));

        $this->assertCount(5, $lines);
        $this->assertStringContainsString('"Date & time",Action,By,"From office","To / at office"', $lines[0]);
        $this->assertStringContainsString(',Registered,"'.$this->clerk->name.'",,"Planning Office"', $lines[1]);
        $this->assertStringContainsString(',Forwarded,"'.$this->mpdoAdmin->name.'","Planning Office",Treasury', $lines[3]);
        $this->assertStringContainsString(',Received,"'.$this->mtoAdmin->name.'",,Treasury', $lines[4]);

        // The tracking record only: remarks stay behind the Security PIN.
        $this->assertStringNotContainsString('remark', strtolower($csv));

        $this->get(route('reports.activity.export.document', [$document, 'format' => 'print']))
            ->assertOk()
            ->assertSee('Affidavit of Non-Filing · '.$document->control_number)
            ->assertSee('Non-filing for J. Cruz')
            ->assertDontSee('Forward remark');

        $this->assertStringStartsWith(
            '%PDF',
            (string) $this->get(route('reports.activity.export.document', [$document, 'format' => 'pdf']))->getContent(),
        );
    }

    public function test_the_by_user_list_and_one_persons_whole_trail_export(): void
    {
        $affidavit = $this->file('Non-filing for J. Cruz', 'Affidavit of Non-Filing');
        $this->act($affidavit, MovementAction::Received, $this->mpdoAdmin);
        $this->act($affidavit, MovementAction::Forwarded, $this->mpdoAdmin, $this->mto);

        $this->actingAs($this->mpdoAdmin);

        $people = $this->get(route('reports.activity.export.users', ['format' => 'csv']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('"Last activity",Name,Office,Actions', $people);
        $this->assertStringContainsString('"'.$this->mpdoAdmin->name.'","Planning Office",2', $people);
        $this->assertStringContainsString('"'.$this->clerk->name.'","Planning Office",1', $people);

        // Past the hundred a click loads: the export is the whole record.
        $start = Carbon::now()->subDay();

        foreach (range(1, 105) as $n) {
            DocumentMovement::factory()->create([
                'document_id' => $affidavit->id,
                'sequence' => 100 + $n,
                'from_office_id' => $this->mpdo->id,
                'to_office_id' => $this->mpdo->id,
                'actor_id' => $this->mpdoAdmin->id,
                'action' => MovementAction::Received,
                'arrived_at' => $start->copy()->addMinutes($n),
                'departed_at' => $start->copy()->addMinutes($n + 1),
            ]);
        }

        $this->getJson(route('reports.activity.user', $this->mpdoAdmin))->assertJsonPath('truncated', true);

        $trail = $this->get(route('reports.activity.export.user', [$this->mpdoAdmin, 'format' => 'csv']))
            ->assertOk()
            ->streamedContent();

        $this->assertCount(108, array_filter(explode("\n", trim($trail))));
        $this->assertStringContainsString('"Date & time",Action,Type,"Control number",Title,"To / at office"', $trail);
        $this->assertStringContainsString(',Forwarded,"Affidavit of Non-Filing",'.$affidavit->control_number.',"Non-filing for J. Cruz",Treasury', $trail);

        $this->get(route('reports.activity.export.user', [$this->mpdoAdmin, 'format' => 'print']))
            ->assertOk()
            ->assertSee($this->mpdoAdmin->name)
            ->assertSee('Office: Planning Office');

        $this->assertStringStartsWith(
            'PK',
            $this->get(route('reports.activity.export.user', [$this->mpdoAdmin, 'format' => 'xlsx']))->streamedContent(),
        );
    }

    public function test_exports_are_scoped_like_the_card(): void
    {
        $ours = $this->file('Ours');
        $theirs = $this->file('Theirs', null, $this->mto);
        $this->act($theirs, MovementAction::Received, $this->mtoAdmin);

        // A plain user gets none of it.
        $this->actingAs($this->clerk);

        foreach ([
            route('reports.activity.export.documents', ['format' => 'csv']),
            route('reports.activity.export.document', [$ours, 'format' => 'csv']),
            route('reports.activity.export.users', ['format' => 'csv']),
            route('reports.activity.export.user', [$this->mpdoAdmin, 'format' => 'csv']),
        ] as $url) {
            $this->get($url)->assertForbidden();
        }

        $this->actingAs($this->mpdoAdmin);

        $list = $this->get(route('reports.activity.export.documents', ['format' => 'csv']))->streamedContent();
        $this->assertStringContainsString($ours->control_number, $list);
        $this->assertStringNotContainsString($theirs->control_number, $list);

        // Another office's document: 404, as the JSON trail answers.
        $this->get(route('reports.activity.export.document', [$theirs, 'format' => 'print']))->assertNotFound();

        // Someone with no activity the viewer may see: 404 too, or the sheet's
        // heading would give away any user's name and office by id.
        $this->get(route('reports.activity.export.user', [$this->mtoAdmin, 'format' => 'print']))->assertNotFound();

        // A Super Admin's sheets cover every office.
        $this->actingAs($this->superAdmin())
            ->get(route('reports.activity.export.documents', ['format' => 'print']))
            ->assertSee('Covering: All offices')
            ->assertSee($theirs->control_number);
    }

    public function test_an_export_over_the_row_cap_is_refused_before_generating(): void
    {
        config(['cicto.reports.max_pdf_rows' => 1, 'cicto.reports.max_xlsx_rows' => 1]);

        $this->file('One');
        $this->file('Two');

        $this->actingAs($this->mpdoAdmin);

        foreach (['print', 'pdf', 'xlsx'] as $format) {
            $this->get(route('reports.activity.export.documents', ['format' => $format]))->assertStatus(422);
            $this->get(route('reports.activity.export.user', [$this->clerk, 'format' => $format]))->assertStatus(422);
        }

        // CSV streams and has no cap: it is the way out the error points to.
        $this->get(route('reports.activity.export.documents', ['format' => 'csv']))->assertOk();
    }
}
