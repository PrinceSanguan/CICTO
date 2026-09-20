<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\TransitionDocument;
use App\Enums\MovementAction;
use App\Models\DocumentScan;
use App\Services\QrCodeRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

class ScanTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_courier_scanning_a_label_sees_the_title_status_and_location(): void
    {
        $office = $this->office('MPDO', 'Planning Office');
        $document = $this->registerDocument($office, $this->staff($office));

        $response = $this->get("/s/{$document->qr_token}");

        $response->assertOk();

        // A separate page, not the staff page with fields hidden -- hiding in
        // the component still ships the data in the Inertia payload.
        //
        // `title` joined this list on 2026-09-20 at the client's request; see
        // ScanController for what that discloses and why they chose it.
        // `description` and `remarks` did not, and that is the line this
        // assertion now exists to hold.
        $response->assertInertia(
            fn ($page) => $page
                ->component('documents/scan-public')
                ->where('document.control_number', $document->control_number)
                ->where('document.title', $document->title)
                ->where('document.current_office', 'Planning Office')
                ->missing('document.description')
                ->missing('document.remarks'),
        );

        /*
         * The page must render the field the controller actually sends.
         *
         * It read `document.resting_office` -- declared in its Props type but
         * never in the payload -- so every public scan printed "Not yet
         * recorded" under "Currently at" for the whole of UAT, while the
         * assertion above stayed green because it checks the PROP, not what the
         * component reads. Asserting the source too is what would have caught it.
         */
        $source = (string) file_get_contents(resource_path('js/pages/documents/scan-public.tsx'));

        $this->assertStringContainsString('document.current_office', $source);
        $this->assertStringNotContainsString('document.resting_office', $source);
    }

    public function test_a_finished_document_still_names_the_office_that_handled_it(): void
    {
        // openMovement is null once a document is terminal, so this field used
        // to go blank under a heading that reads "Last handled by" -- the one
        // moment a courier most needs an office name.
        $office = $this->office('MTO', 'Treasury');
        $document = $this->registerDocument($office, $this->staff($office));

        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Received,
            actor: $this->admin($office),
        );
        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Completed,
            actor: $this->admin($office),
        );

        $this->assertNull($document->fresh()->openMovement);

        $this->get("/s/{$document->qr_token}")
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page->where('document.current_office', 'Treasury'),
            );
    }

    /**
     * The title is shown to ANYONE holding the folder, which is the point and
     * also the cost.
     *
     * This test used to assert the exact opposite, with this same example
     * string. It is kept, inverted, rather than deleted: the pairing of "a
     * title that should not travel" with "it travels" is the clearest record
     * of a decision the client made on 2026-09-20, and the next person to read
     * it should see the trade rather than a bare green tick.
     */
    public function test_the_scan_page_shows_the_document_title_to_the_public(): void
    {
        $office = $this->office();
        $document = $this->registerDocument($office, $this->staff($office));
        $document->forceFill(['title' => 'Confidential disciplinary case'])->save();

        $this->get("/s/{$document->qr_token}")
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page->where(
                    'document.title',
                    'Confidential disciplinary case',
                ),
            );
    }

    /** A title names the document. These two are its contents, and stay in. */
    public function test_the_scan_payload_still_withholds_description_and_remarks(): void
    {
        $office = $this->office();
        $document = $this->registerDocument($office, $this->staff($office));
        $document->forceFill([
            'description' => 'Findings against the respondent employee',
        ])->save();

        $this->get("/s/{$document->qr_token}")
            ->assertOk()
            ->assertDontSee('Findings against the respondent employee', escape: false)
            ->assertInertia(
                fn ($page) => $page
                    ->missing('document.description')
                    ->missing('document.remarks'),
            );
    }

    /**
     * The title has to survive the trip to the SCREEN, not just the payload.
     *
     * ScanTest has been caught by exactly this before: it asserted a prop the
     * component never read, and "Currently at" printed "Not yet recorded"
     * through the whole of UAT with the suite green.
     */
    public function test_the_scan_page_renders_the_title_under_the_control_number(): void
    {
        $source = (string) file_get_contents(
            resource_path('js/pages/documents/scan-public.tsx'),
        );

        $code = strpos($source, '{document.control_number}');
        $title = strpos($source, '{document.title}');

        $this->assertNotFalse($title, 'The page never reads document.title.');
        $this->assertNotFalse($code);
        $this->assertGreaterThan(
            $code,
            $title,
            'The title must render BELOW the control number, not above it.',
        );
    }

    public function test_staff_who_may_read_the_document_are_redirected_to_the_full_view(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $document = $this->registerDocument($office, $this->staff($office));

        $this->actingAs($admin)
            ->get("/s/{$document->qr_token}")
            ->assertRedirect(route('documents.show', $document));
    }

    public function test_an_unknown_token_renders_a_friendly_page_rather_than_a_404(): void
    {
        $this->get('/s/notarealtokenatallxxxxxxxxx')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('documents/scan-not-found'));
    }

    public function test_a_malformed_token_does_not_reach_the_database(): void
    {
        $this->get('/s/TOO-SHORT')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('documents/scan-not-found'));
    }

    public function test_scans_are_recorded_once_per_window_not_once_per_frame(): void
    {
        $office = $this->office();
        $document = $this->registerDocument($office, $this->staff($office));

        Carbon::setTestNow('2026-08-09 10:00:00');

        // A phone camera fires the reader repeatedly at one label.
        $this->get("/s/{$document->qr_token}");
        $this->get("/s/{$document->qr_token}");
        $this->get("/s/{$document->qr_token}");

        $this->assertSame(1, DocumentScan::query()->count());

        // Past the dedupe window it counts as a new sighting.
        Carbon::setTestNow('2026-08-09 10:05:00');
        $this->get("/s/{$document->qr_token}");

        $this->assertSame(2, DocumentScan::query()->count());
    }

    public function test_a_scan_is_not_a_transfer_and_never_touches_the_ledger(): void
    {
        $office = $this->office();
        $document = $this->registerDocument($office, $this->staff($office));

        $before = $document->movements()->count();

        $this->get("/s/{$document->qr_token}");

        $this->assertSame($before, $document->fresh()->movements()->count());
        $this->assertSame(1, DocumentScan::query()->count());
    }

    public function test_the_qr_image_route_is_policy_gated(): void
    {
        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');

        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        $this->actingAs($this->admin($mto))
            ->get(route('documents.qr', $document))
            ->assertForbidden();

        $response = $this->actingAs($this->admin($mpdo))
            ->get(route('documents.qr', $document));

        $response->assertOk();
        $response->assertHeader('content-type', 'image/svg+xml');
        $this->assertStringContainsString('<svg', $response->getContent());
    }

    public function test_the_qr_image_revalidates_so_a_scan_domain_change_reaches_the_browser(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $document = $this->registerDocument($office, $this->staff($office));

        config()->set('cicto.scan_base_url', 'https://old.example.test');

        $first = $this->actingAs($admin)->get(route('documents.qr', $document));
        $cacheControl = (string) $first->headers->get('Cache-Control');
        $etag = (string) $first->headers->get('ETag');

        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringNotContainsString('immutable', $cacheControl);
        $this->assertNotSame('', $etag);

        $this->actingAs($admin)
            ->get(route('documents.qr', $document), ['If-None-Match' => $etag])
            ->assertStatus(304);

        config()->set('cicto.scan_base_url', 'https://new.example.test');

        $this->actingAs($admin)
            ->get(route('documents.qr', $document), ['If-None-Match' => $etag])
            ->assertOk();
    }

    public function test_the_printable_label_encodes_the_token_and_not_the_control_number(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $document = $this->registerDocument($office, $this->staff($office));

        $url = app(QrCodeRenderer::class)->urlFor($document);

        $this->assertStringEndsWith("/s/{$document->qr_token}", $url);
        $this->assertStringNotContainsString($document->control_number, $url);

        // The label sheet itself is plain Blade so printing never waits on the
        // SPA, and the human-readable control number is still on the sticker.
        $this->actingAs($admin)
            ->get(route('documents.labels.print', ['ids' => [$document->id]]))
            ->assertOk()
            ->assertSee($document->control_number)
            ->assertSee('<svg', escape: false);
    }

    /**
     * THE BUG REPORTED 2026-09-20: an office typed the control number printed
     * on the label into the box that says "or type the code", and was told the
     * document does not exist.
     *
     * The console posted everything to the public /s/{token} path, which
     * resolves the 26-character QR token and nothing else -- so the one
     * identifier a human can read off the label was the one it could not use.
     */
    public function test_the_staff_scan_box_resolves_a_control_number(): void
    {
        $office = $this->office('MPDO', 'Planning Office');
        $document = $this->registerDocument($office, $this->staff($office));

        $this->actingAs($this->admin($office))
            ->get(route('documents.scan.resolve', ['code' => $document->control_number]))
            ->assertRedirect(route('documents.show', $document));
    }

    /** A QR token still goes to the public path, which decides what to show. */
    public function test_the_staff_scan_box_still_resolves_a_qr_token(): void
    {
        $office = $this->office();
        $document = $this->registerDocument($office, $this->staff($office));

        $this->actingAs($this->admin($office))
            ->get(route('documents.scan.resolve', ['code' => $document->qr_token]))
            ->assertRedirect(route('scan.show', ['token' => $document->qr_token]));
    }

    /** A wedge scanner types the whole URL, so the whole URL has to work. */
    public function test_the_staff_scan_box_accepts_a_whole_scanned_url(): void
    {
        $office = $this->office();
        $document = $this->registerDocument($office, $this->staff($office));

        $this->actingAs($this->admin($office))
            ->get(route('documents.scan.resolve', [
                'code' => 'https://cicto.site/s/'.$document->qr_token,
            ]))
            ->assertRedirect(route('scan.show', ['token' => $document->qr_token]));
    }

    /**
     * A control number is SEQUENTIAL, so the public path must never resolve
     * one -- otherwise anybody could walk OCM-2026-00001 upwards and read the
     * status, office and title of every document in the register.
     */
    public function test_the_public_path_still_refuses_a_control_number(): void
    {
        $office = $this->office();
        $document = $this->registerDocument($office, $this->staff($office));

        $this->get('/s/'.$document->control_number)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('documents/scan-not-found'));
    }

    /** ...and the staff resolver is not a way in for someone with no session. */
    public function test_the_staff_resolver_needs_a_session(): void
    {
        $office = $this->office();
        $document = $this->registerDocument($office, $this->staff($office));

        $this->get(route('documents.scan.resolve', ['code' => $document->control_number]))
            ->assertRedirect(route('login'));
    }

    /**
     * A document you cannot read must NOT be reported as non-existent.
     *
     * The first version answered this exactly like an invented code, so the
     * box could not be used to confirm which control numbers exist elsewhere.
     * Within hours it cost what a lie costs: an office typed a control number
     * off the label in their hand, were told nothing matched, and queried the
     * production database to find the document sitting there (2026-09-21).
     *
     * What the honest answer reveals is EXISTENCE alone -- no title, no
     * status, not even the holding office. See ScanController::resolve.
     */
    public function test_a_document_you_may_not_read_says_so_rather_than_denying_it_exists(): void
    {
        $mine = $this->office('MPDO', 'Planning Office');
        $theirs = $this->office('TREA', 'Treasury');

        $hidden = $this->registerDocument($theirs, $this->staff($theirs));
        $admin = $this->admin($mine);

        $this->actingAs($admin)
            ->get(route('documents.scan.resolve', ['code' => $hidden->control_number]))
            ->assertRedirect(route('documents.scan'));

        $this->actingAs($admin)
            ->get(route('documents.scan'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('miss.code', $hidden->control_number)
                ->where('miss.reason', 'forbidden'));
    }

    /** ...and refusing still gives away nothing about the document itself. */
    public function test_a_refusal_reveals_nothing_but_the_control_number(): void
    {
        $mine = $this->office('MPDO', 'Planning Office');
        $theirs = $this->office('TREA', 'Treasury');

        $hidden = $this->registerDocument($theirs, $this->staff($theirs));
        $hidden->forceFill(['title' => 'Confidential disciplinary case'])->save();

        $this->actingAs($this->admin($mine))
            ->get(route('documents.scan.resolve', ['code' => $hidden->control_number]));

        $this->actingAs($this->admin($mine))
            ->get(route('documents.scan'))
            ->assertOk()
            ->assertDontSee('Confidential disciplinary case', escape: false)
            ->assertDontSee('Treasury', escape: false)
            ->assertInertia(fn ($page) => $page->missing('miss.title'));
    }

    /** An invented code is a different answer from a real one. */
    public function test_a_miss_returns_to_the_console_and_says_what_it_could_not_find(): void
    {
        $office = $this->office();

        $this->actingAs($this->admin($office))
            ->get(route('documents.scan.resolve', ['code' => 'MPDO-2026-99999']))
            ->assertRedirect(route('documents.scan'));

        $this->actingAs($this->admin($office))
            ->get(route('documents.scan'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('documents/scan')
                ->where('miss.code', 'MPDO-2026-99999')
                ->where('miss.reason', 'missing'));
    }
}
