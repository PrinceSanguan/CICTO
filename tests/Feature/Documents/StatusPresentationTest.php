<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\TransitionDocument;
use App\Enums\DocumentStatus;
use App\Enums\MovementAction;
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

        // returned -> also "Pending", via a different workflow state
        $returned = $this->registerDocument($office, $clerk);
        foreach ([MovementAction::Received, MovementAction::Returned] as $action) {
            $returned->refresh();
            app(TransitionDocument::class)->handle(
                document: $returned,
                action: $action,
                actor: $admin,
                expectedMovementId: $returned->openMovement?->id,
            );
        }

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
