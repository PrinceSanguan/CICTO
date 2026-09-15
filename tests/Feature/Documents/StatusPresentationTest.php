<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentStatus;
use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * §8's status column shows four public labels over six workflow states.
 *
 * The colour has to be a function of the LABEL, not the state. Deriving it from
 * the state meant two rows both reading "Pending" rendered in different colours
 * -- a legend nobody can learn, and the kind of thing that gets read as a
 * meaningful distinction that does not exist.
 */
class StatusPresentationTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    public function test_every_public_label_has_exactly_one_colour(): void
    {
        $tonesByLabel = [];

        foreach (DocumentStatus::cases() as $status) {
            $tonesByLabel[$status->publicLabel()][] = $status->publicTone();
        }

        foreach ($tonesByLabel as $label => $tones) {
            $this->assertCount(
                1,
                array_unique($tones),
                "\"{$label}\" renders in more than one colour: ".implode(', ', array_unique($tones)),
            );
        }

        // And the four labels must not collide onto one colour either, or the
        // column stops carrying information.
        $distinct = array_unique(array_map(
            fn (array $tones) => $tones[0],
            $tonesByLabel,
        ));

        $this->assertCount(count($tonesByLabel), $distinct);
    }

    /**
     * The public scan page is seen by couriers and citizens with no account.
     * It pairs publicLabel() with a tone, and those two must come from the same
     * mapping -- a returned document and a legacy rejected one both read
     * "Returned", so rendering them in different colours invents a distinction
     * on the one screen where the reader has the least context to question it.
     */
    public function test_the_public_scan_page_pairs_its_label_and_colour(): void
    {
        $office = $this->office();
        $clerk = $this->staff($office);

        $this->assertSame('Pending', $this->scanned($this->registerDocument($office, $clerk))['status_label']);

        $tones = [];

        foreach ([DocumentStatus::Returned, DocumentStatus::Rejected] as $status) {
            // Written straight to the column: nothing can reach `rejected` any
            // more, and the scan page does not care how a document got there.
            $document = $this->registerDocument($office, $clerk);
            $document->forceFill(['status' => $status->value])->save();

            $props = $this->scanned($document->fresh());

            $this->assertSame('Returned', $props['status_label']);
            $tones[] = $props['status_tone'];
        }

        $this->assertCount(
            1,
            array_unique($tones),
            'Two documents both reading "Returned" rendered in different colours: '
            .implode(' vs ', $tones),
        );
    }

    /**
     * §8's four words, with the client's rename of 2026-09-16: "i eto po sir yung
     * word na 'reject' gagawing 'returned'". Rejected must not come back.
     */
    public function test_the_public_labels_are_the_four_the_contract_names(): void
    {
        $labels = array_values(array_unique(array_map(
            fn (DocumentStatus $status) => $status->publicLabel(),
            DocumentStatus::cases(),
        )));

        sort($labels);

        $this->assertSame(
            ['Completed', 'In Process', 'Pending', 'Returned'],
            $labels,
        );
    }

    /** The Track Documents status filter, which is where the client saw "Rejected". */
    public function test_the_track_documents_filter_offers_returned_and_finds_both_kinds(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $clerk = $this->staff($office);

        $this->registerDocument($office, $clerk);

        $returned = $this->registerDocument($office, $clerk);
        $returned->forceFill(['status' => DocumentStatus::Returned->value])->save();

        $rejected = $this->registerDocument($office, $clerk);
        $rejected->forceFill(['status' => DocumentStatus::Rejected->value])->save();

        $statuses = $this->actingAs($admin)
            ->get(route('documents.index'))
            ->assertOk()
            ->viewData('page')['props']['statuses'];

        $this->assertSame(
            ['Pending', 'In Process', 'Returned', 'Completed'],
            array_column($statuses, 'label'),
        );

        // A bookmarked ?status=rejected lands on the filter that replaced it.
        foreach (['returned', 'rejected'] as $value) {
            $props = $this->actingAs($admin)
                ->get(route('documents.index', ['status' => $value]))
                ->assertOk()
                ->viewData('page')['props'];

            $this->assertEqualsCanonicalizing(
                [$returned->control_number, $rejected->control_number],
                array_column($props['documents']['data'], 'control_number'),
            );
            $this->assertSame('returned', $props['filters']['status']);
        }
    }

    /** @return array<string, mixed> */
    private function scanned(Document $document): array
    {
        return $this->get('/s/'.$document->qr_token)
            ->assertOk()
            ->viewData('page')['props']['document'];
    }
}
