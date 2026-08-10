<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentPriority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * priority is a string column, so the obvious ORDER BY sorts it alphabetically
 * -- high, low, normal, urgent -- which silently buries urgent work below
 * routine work in the office queue. The controller ranks it explicitly; this
 * asserts the ranking still says what the enum says.
 */
class AdminQueueOrderTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    public function test_the_office_queue_lists_urgent_work_before_routine_work(): void
    {
        $office = $this->office('MPDO');
        $admin = $this->admin($office);
        $clerk = $this->staff($office);
        $type = $this->documentType();

        $created = [];

        foreach (DocumentPriority::cases() as $priority) {
            $created[$priority->value] = $this
                ->registerDocument($office, $clerk, $type, $priority)
                ->control_number;
        }

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));
        $response->assertOk();

        $queue = collect($response->viewData('page')['props']['queue'] ?? [])
            ->pluck('priority')
            ->all();

        $this->assertNotEmpty($queue, 'The admin queue should list the seeded documents.');

        // Highest weight first, straight from the enum -- so adding a case
        // without updating the controller's CASE expression fails here.
        $expected = collect(DocumentPriority::cases())
            ->sortByDesc(fn (DocumentPriority $p) => $p->weight())
            ->pluck('value')
            ->values()
            ->all();

        $this->assertSame($expected, $queue);

        // The specific trap: alphabetically 'high' sorts below 'low'.
        $this->assertLessThan(
            array_search('low', $queue, true),
            array_search('high', $queue, true),
            "'high' priority must outrank 'low' -- a plain string ORDER BY gets this backwards.",
        );
    }
}
