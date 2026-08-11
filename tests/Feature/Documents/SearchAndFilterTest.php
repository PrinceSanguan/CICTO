<?php

namespace Tests\Feature\Documents;

use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * §8 / feature #4 Search and Filter.
 *
 * The first test here is the one that proves the whole portability rule in
 * docs/DATABASE.md: MySQL's default collation is case-insensitive and
 * PostgreSQL's is not, so a search that works on one silently fails on the
 * other unless BOTH sides are lowered.
 */
class SearchAndFilterTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    public function test_a_lowercase_search_finds_an_uppercase_control_number(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $document = $this->registerDocument($office, $this->staff($office));

        $this->assertSame(
            mb_strtoupper($document->control_number),
            $document->control_number,
            'Control numbers are expected to be uppercase.',
        );

        $rows = $this->actingAs($admin)
            ->get(route('documents.index', [
                'q' => mb_strtolower($document->control_number),
            ]))
            ->assertOk()
            ->viewData('page')['props']['documents']['data'];

        $this->assertCount(1, $rows);
        $this->assertSame($document->control_number, $rows[0]['control_number']);
    }

    public function test_a_percent_sign_does_not_match_every_row(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);

        foreach (range(1, 3) as $ignored) {
            $this->registerDocument($office, $this->staff($office));
        }

        // Unescaped, `%` is a LIKE wildcard and this returns the whole table --
        // so a clerk searching for "100%" would appear to have no filter at all.
        $rows = $this->actingAs($admin)
            ->get(route('documents.index', ['q' => '100%']))
            ->assertOk()
            ->viewData('page')['props']['documents']['data'];

        $this->assertCount(0, $rows);

        // The underscore wildcard is escaped too.
        $rows = $this->actingAs($admin)
            ->get(route('documents.index', ['q' => '_']))
            ->assertOk()
            ->viewData('page')['props']['documents']['data'];

        $this->assertCount(0, $rows);
    }

    public function test_an_invalid_sort_column_is_rejected_rather_than_interpolated(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);

        // sort and dir reach orderBy(). Anything but the whitelist must fail
        // validation rather than reach the query builder.
        $this->actingAs($admin)
            ->get(route('documents.index', ['sort' => 'password']))
            ->assertSessionHasErrors('sort');

        $this->actingAs($admin)
            ->get(route('documents.index', ['dir' => 'sideways']))
            ->assertSessionHasErrors('dir');

        $this->actingAs($admin)
            ->get(route('documents.index', ['per_page' => 999]))
            ->assertSessionHasErrors('per_page');
    }

    public function test_page_two_does_not_repeat_page_one(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $clerk = $this->staff($office);

        // All created in the same second, so created_at ties and only the id
        // tiebreak keeps the ordering total.
        $seed = $this->registerDocument($office, $clerk);
        $now = now();

        $rows = [];

        for ($i = 0; $i < 29; $i++) {
            $rows[] = [
                'control_number' => sprintf('BAL-2026-%05d', $i + 500),
                'title' => 'Bulk '.$i,
                'document_type_id' => $seed->document_type_id,
                'originating_office_id' => $office->id,
                'created_by_id' => $clerk->id,
                'status' => $seed->status->value,
                'priority' => $seed->priority->value,
                'qr_token' => mb_strtoupper(Str::random(26)),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        Document::query()->insert($rows);

        $first = collect(
            $this->actingAs($admin)
                ->get(route('documents.index', ['per_page' => 10]))
                ->viewData('page')['props']['documents']['data'],
        )->pluck('id');

        $second = collect(
            $this->actingAs($admin)
                ->get(route('documents.index', ['per_page' => 10, 'page' => 2]))
                ->viewData('page')['props']['documents']['data'],
        )->pluck('id');

        $this->assertCount(10, $first);
        $this->assertCount(10, $second);
        $this->assertEmpty(
            $first->intersect($second),
            'Page 2 repeated rows from page 1 — the id tiebreak is missing.',
        );
    }

    public function test_an_admin_never_sees_another_offices_documents(): void
    {
        $mine = $this->office('MPDO');
        $admin = $this->admin($mine);
        $this->registerDocument($mine, $this->staff($mine));

        $elsewhere = $this->office('HRMO');
        $hidden = $this->registerDocument($elsewhere, $this->staff($elsewhere));

        $rows = $this->actingAs($admin)
            ->get(route('documents.index'))
            ->viewData('page')['props']['documents']['data'];

        $this->assertNotContains(
            $hidden->control_number,
            collect($rows)->pluck('control_number')->all(),
        );

        // And searching for it by name does not reveal it either.
        $rows = $this->actingAs($admin)
            ->get(route('documents.index', ['q' => $hidden->control_number]))
            ->viewData('page')['props']['documents']['data'];

        $this->assertCount(0, $rows);
    }

    public function test_a_non_numeric_document_segment_never_reaches_the_binder(): void
    {
        $office = $this->office();

        // `documents/archived` must be a 404, not a 500 and not a document
        // lookup for a row named "archived".
        $this->actingAs($this->admin($office))
            ->get('/documents/archived')
            ->assertNotFound();
    }
}
