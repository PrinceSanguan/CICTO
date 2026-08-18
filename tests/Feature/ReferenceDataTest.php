<?php

namespace Tests\Feature;

use App\Actions\Documents\AllocateControlNumber;
use App\Models\DocumentType;
use App\Models\Office;
use App\Support\Deadlines;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\OfficeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * The client's real office and document-type lists (question A4, supplied
 * 2026-08-18).
 *
 * These are the only two seeders that run in production, and every dropdown,
 * every route target and every control-number prefix comes out of them. Nothing
 * else in the application validates them: there is no office admin screen, no
 * document-type screen and no form request for either, so the shape assertions
 * below are the only gate between a typo in a reference list and a control
 * number that cannot be issued.
 */
class ReferenceDataTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([OfficeSeeder::class, DocumentTypeSeeder::class]);
    }

    public function test_every_office_code_fits_a_control_number(): void
    {
        // documents.control_number is varchar(40) and the format spends 11
        // characters on -YYYY-NNNNN. A code of 30 characters seeds cleanly and
        // then fails on the first document registered from that office, which
        // is a long way from where the mistake was made.
        foreach (Office::query()->pluck('code') as $code) {
            $this->assertLessThanOrEqual(
                29,
                mb_strlen($code),
                "Office code {$code} leaves no room for -YYYY-NNNNN inside varchar(40).",
            );
        }
    }

    public function test_a_hyphenated_office_code_still_produces_a_usable_control_number(): void
    {
        // Seven of the client's real codes contain a hyphen (GSO-MS, OCM-LYDO,
        // CENRO-SS, EEAO-PM, EEAO-CS, OCM-TF, OCM-SH). Nothing in the system
        // parses a control number, so this is about the string fitting and the
        // search finding it -- not about splitting it back apart.
        $office = Office::query()->where('code', 'OCM-LYDO')->firstOrFail();

        $number = app(AllocateControlNumber::class)->handle($office);

        $this->assertSame('OCM-LYDO-'.now()->year.'-00001', $number);
        $this->assertLessThanOrEqual(40, mb_strlen($number));
    }

    public function test_no_two_active_offices_share_a_name(): void
    {
        // The supplied file lists the City Social Welfare and Development
        // Officer twice, under CSWDO and CSWD. Both rows exist so neither alias
        // can be reused for a different office, but only one may be selectable
        // -- two identical entries in a "send to" list is a bug report.
        $duplicates = Office::query()
            ->where('is_active', true)
            ->select('name')
            ->groupBy('name')
            ->havingRaw('count(*) > 1')
            ->pluck('name');

        $this->assertEmpty(
            $duplicates,
            'Two active offices share a name: '.$duplicates->implode(', '),
        );
    }

    public function test_the_offices_the_practice_data_and_the_help_text_name_all_exist(): void
    {
        // OCM, TREA and SP are named in DemoDataCommand, DatabaseSeeder and the
        // client's printed testing checklist; ARO and CICTO are named in the
        // documentation. A rename that misses one of them fails silently.
        foreach (['OCM', 'TREA', 'SP', 'ARO', 'CICTO'] as $code) {
            $this->assertTrue(
                Office::query()->where('code', $code)->where('is_active', true)->exists(),
                "Office {$code} is missing or inactive.",
            );
        }
    }

    public function test_re_seeding_retires_the_placeholder_offices_rather_than_deleting_them(): void
    {
        // The invented list this replaced. Offices are the target of a foreign
        // key from documents and movements, and control numbers already issued
        // under them have to keep resolving -- so they are deactivated, never
        // dropped.
        Office::query()->create([
            'code' => 'MPDO',
            'name' => 'Municipal Planning and Development Office',
            'is_active' => true,
        ]);

        $this->seed(OfficeSeeder::class);

        $retired = Office::query()->where('code', 'MPDO')->first();

        $this->assertNotNull($retired, 'A retired office was deleted, orphaning its control numbers.');
        $this->assertFalse($retired->is_active, 'A retired placeholder office is still selectable.');
    }

    public function test_re_seeding_retires_the_placeholder_document_types(): void
    {
        DocumentType::query()->create([
            'code' => 'ORD',
            'name' => 'Ordinance / Resolution',
            'is_active' => true,
        ]);

        $this->seed(DocumentTypeSeeder::class);

        $retired = DocumentType::query()->where('code', 'ORD')->first();

        $this->assertNotNull($retired);
        $this->assertFalse($retired->is_active, 'A retired placeholder document type is still on the Submit form.');
    }

    public function test_no_document_type_claims_a_turnaround_the_client_has_not_given(): void
    {
        /*
         * The client answered "how many days should each type take?" with
         * "ARO" -- a different office, not yet asked. Every row therefore
         * carries NULL rather than a guess, and this test exists so that
         * somebody filling the column in later does it because ARO answered,
         * not because a blank looked untidy.
         *
         * Delete this test when the real numbers land; it is a marker, not a
         * rule.
         */
        $this->assertSame(
            0,
            DB::table('document_types')->whereNotNull('turnaround_days')->count(),
            'A document type carries an invented turnaround. Client question A4 is still open with ARO.',
        );
    }

    public function test_a_type_with_no_agreed_turnaround_falls_back_to_the_installation_default(): void
    {
        // This is the branch every document in the system now takes, and it was
        // uncovered while it was the branch none of them took.
        config(['cicto.deadlines.default_turnaround_days' => 4]);

        $type = DocumentType::query()->whereNull('turnaround_days')->firstOrFail();

        $due = Deadlines::dueAt($type, now());

        $this->assertSame(now()->addDays(4)->toDateString(), $due->toDateString());
        $this->assertSame((int) config('cicto.deadlines.business_end_hour'), $due->hour);
    }

    /**
     * Retiring an office must not quietly re-file its staff somewhere else.
     *
     * A <select> told to default to a value none of its options carry does not
     * stay blank -- the browser picks the first selectable option. For a clerk
     * whose office was deactivated by a re-seed that pre-fills a stranger's
     * department, and the form submits happily: the document lands under the
     * wrong office and that office's code is burned into a control number that
     * gets printed on a label. Sending null instead leaves the placeholder
     * showing and `required` blocks the submit.
     */
    public function test_the_submit_form_does_not_preselect_an_office_it_is_not_offering(): void
    {
        $retired = Office::query()->create([
            'code' => 'MPDO',
            'name' => 'Municipal Planning and Development Office',
            'is_active' => false,
        ]);

        $stranded = $this->staff($retired);

        $this->actingAs($stranded)
            ->get(route('documents.create'))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('defaultOfficeId', null)
                ->where('offices', fn (Collection $offices) => $offices
                    ->doesntContain(fn (array $office) => $office['id'] === $retired->id)));
    }

    public function test_the_submit_form_still_preselects_an_office_that_is_active(): void
    {
        $office = Office::query()->where('code', 'OCM')->firstOrFail();
        $clerk = $this->staff($office);

        $this->actingAs($clerk)
            ->get(route('documents.create'))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page->where('defaultOfficeId', $office->id));
    }

    public function test_no_two_active_document_types_share_a_name(): void
    {
        // The placeholder MEMO type and a real "Memorandum" both active would
        // give the type filter two entries with one label -- and the one on
        // offer would silently exclude every memorandum filed before the
        // upgrade. Codes that name the same thing are re-used, not duplicated.
        $duplicates = DocumentType::query()
            ->where('is_active', true)
            ->select('name')
            ->groupBy('name')
            ->havingRaw('count(*) > 1')
            ->pluck('name');

        $this->assertEmpty(
            $duplicates,
            'Two active document types share a name: '.$duplicates->implode(', '),
        );
    }

    public function test_a_deadline_lands_at_the_close_of_business_the_client_confirmed(): void
    {
        // Monday to Thursday, 7:00 AM to 6:00 PM (confirmed 2026-08-18). The
        // hour matters: a deadline at 00:00 reads as overdue to somebody who
        // had the whole working day.
        $due = Deadlines::dueAt($this->documentType(2), now()->setTime(9, 0));

        $this->assertSame(18, $due->hour);
        $this->assertSame(0, $due->minute);
    }
}
