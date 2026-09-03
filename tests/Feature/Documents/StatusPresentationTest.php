<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentStatus;
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
     * mapping -- an initiated and a returned document both read "Pending", so
     * rendering them in different colours invents a distinction on the one
     * screen where the reader has the least context to question it.
     */
    public function test_the_public_scan_page_pairs_its_label_and_colour(): void
    {
        $office = $this->office();
        $clerk = $this->staff($office);
        $admin = $this->admin($office);

        // initiated -> "Pending"
        $initiated = $this->registerDocument($office, $clerk);

        /*
         * returned -> also "Pending", via a different workflow state.
         *
         * Written straight to the column because returning was removed as an
         * ACTION on 2026-09-03. It is still a stored STATUS -- documents the
         * client returned before that date sit in it, and a courier scanning
         * one of those labels is exactly the reader this pairing protects.
         */
        $returned = $this->registerDocument($office, $clerk);
        $returned->forceFill(['status' => DocumentStatus::Returned->value])->save();

        $this->assertSame('returned', $returned->fresh()->status->value);

        $tones = [];

        foreach ([$initiated, $returned->fresh()] as $document) {
            $props = $this->get('/s/'.$document->qr_token)
                ->assertOk()
                ->viewData('page')['props']['document'];

            $this->assertSame('Pending', $props['status_label']);
            $tones[] = $props['status_tone'];
        }

        $this->assertCount(
            1,
            array_unique($tones),
            'Two documents both reading "Pending" rendered in different colours: '
            .implode(' vs ', $tones),
        );
    }

    public function test_the_public_labels_are_the_four_the_contract_names(): void
    {
        $labels = array_values(array_unique(array_map(
            fn (DocumentStatus $status) => $status->publicLabel(),
            DocumentStatus::cases(),
        )));

        sort($labels);

        $this->assertSame(
            ['Completed', 'In Process', 'Pending', 'Rejected'],
            $labels,
        );
    }
}
