<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\TransitionDocument;
use App\Enums\MovementAction;
use App\Models\DocumentMovement;
use App\Support\Database\Duration;
use App\Support\Presenters\DocumentPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

class AuditTrailTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_trail_records_who_did_what_and_how_long_it_sat_at_each_office(): void
    {
        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');
        $mayor = $this->office('MO', "Mayor's Office");

        $clerk = $this->staff($mpdo);
        $mpdoAdmin = $this->admin($mpdo);
        $mtoAdmin = $this->admin($mto);

        Carbon::setTestNow('2026-08-03 09:12:00');
        $document = $this->registerDocument($mpdo, $clerk);

        $transition = app(TransitionDocument::class);

        // Sat at MPDO for 6h 28m.
        Carbon::setTestNow('2026-08-03 15:40:00');
        $transition->handle(
            document: $document,
            action: MovementAction::Forwarded,
            actor: $mpdoAdmin,
            toOfficeId: $mto->id,
            expectedMovementId: $document->openMovement->id,
        );

        // Sat at MTO for 2d 19h 25m.
        Carbon::setTestNow('2026-08-06 11:05:00');
        $document->refresh();
        $transition->handle(
            document: $document,
            action: MovementAction::Forwarded,
            actor: $mtoAdmin,
            toOfficeId: $mayor->id,
            expectedMovementId: $document->openMovement->id,
        );

        $legs = DocumentMovement::query()
            ->where('document_id', $document->id)
            ->orderBy('sequence')
            ->get();

        $this->assertSame(388, $legs[0]->dwellMinutes());   // 6h 28m at MPDO
        $this->assertSame(4045, $legs[1]->dwellMinutes());  // 2d 19h 25m at MTO
        $this->assertNull($legs[2]->dwellMinutes(), 'The open leg has not finished sitting anywhere.');

        // Each leg keeps the action and actor that CREATED it. The genesis
        // entry must survive every later transition -- an earlier build
        // overwrote it on the first forward, and §13's trail silently lost its
        // first event.
        $this->assertSame(MovementAction::Registered, $legs[0]->action);
        $this->assertSame($clerk->id, $legs[0]->actor_id);

        $this->assertSame(MovementAction::Forwarded, $legs[1]->action);
        $this->assertSame($mpdoAdmin->id, $legs[1]->actor_id);
        $this->assertSame($mpdo->id, $legs[1]->from_office_id);
        $this->assertSame($mto->id, $legs[1]->to_office_id);

        $this->assertSame(MovementAction::Forwarded, $legs[2]->action);
        $this->assertSame($mtoAdmin->id, $legs[2]->actor_id);
        $this->assertSame($mayor->id, $legs[2]->to_office_id);
    }

    public function test_the_sql_duration_helper_agrees_with_the_php_calculation(): void
    {
        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');

        Carbon::setTestNow('2026-08-03 09:12:00');
        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        Carbon::setTestNow('2026-08-03 15:40:00');
        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Forwarded,
            actor: $this->admin($mpdo),
            toOfficeId: $mto->id,
            expectedMovementId: $document->openMovement->id,
        );

        // Duration is the one place that branches on driver. This asserts the
        // SQL expression returns the same number the PHP path does, which is
        // what has to hold identically on PostgreSQL and MySQL.
        $expression = Duration::minutesBetween(
            'document_movements.arrived_at',
            'document_movements.departed_at',
        );

        $minutes = DB::table('document_movements')
            ->where('document_id', $document->id)
            ->whereNotNull('departed_at')
            ->selectRaw("{$expression} as minutes")
            ->value('minutes');

        $this->assertSame(388, (int) $minutes);
    }

    public function test_the_timeline_presenter_labels_the_open_leg_as_still_here(): void
    {
        $office = $this->office();
        $document = $this->registerDocument($office, $this->staff($office));

        $rows = app(DocumentPresenter::class)->timeline($document->movements()->get());

        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['is_open']);
        $this->assertNull($rows[0]['dwell_minutes']);
        $this->assertSame('registered', $rows[0]['verb']);
    }

    public function test_reading_a_document_never_writes_to_the_ledger(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $document = $this->registerDocument($office, $this->staff($office));

        $before = DocumentMovement::query()->count();

        $this->actingAs($admin)->get(route('documents.show', $document))->assertOk();

        // The custody timeline records transfers, not reads. A download or a
        // page view must never appear in it.
        $this->assertSame($before, DocumentMovement::query()->count());
    }

    public function test_the_per_office_rollup_answers_how_long_it_sat_at_each_office(): void
    {
        $mpdo = $this->office('MPDO', 'Planning Office');
        $mto = $this->office('MTO', 'Treasury');

        Carbon::setTestNow('2026-08-03 09:12:00');
        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        Carbon::setTestNow('2026-08-03 15:40:00');
        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Forwarded,
            actor: $this->admin($mpdo),
            toOfficeId: $mto->id,
            expectedMovementId: $document->openMovement->id,
        );

        Carbon::setTestNow('2026-08-04 15:40:00');
        $document->refresh();

        $rollup = collect(
            app(DocumentPresenter::class)->officeRollup($document->movements()->get()),
        )->keyBy('office');

        // §13: "how long it stayed at each stage/office."
        $this->assertSame(388, $rollup['Planning Office']['minutes']);
        $this->assertFalse($rollup['Planning Office']['is_current']);

        // The open leg counts time so far, or a document parked for a week
        // would report zero.
        $this->assertSame(1440, $rollup['Treasury']['minutes']);
        $this->assertTrue($rollup['Treasury']['is_current']);
        $this->assertSame('1d', $rollup['Treasury']['duration']);
    }
}
