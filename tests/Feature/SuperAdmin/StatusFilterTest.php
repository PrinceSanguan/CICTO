<?php

namespace Tests\Feature\SuperAdmin;

use App\Enums\DocumentStatus;
use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * §4 All Documents, the Super Admin's status filter.
 *
 * The client pointed at this dropdown on 2026-09-19 and asked for "Rejected"
 * to go: Return replaced Reject on 2026-09-15, and this was the last list still
 * offering the word. The documents refused under the old button still exist,
 * so Returned has to find them -- nothing may drop out of the register.
 */
class StatusFilterTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    public function test_returned_finds_returned_and_legacy_rejected_documents(): void
    {
        [$returned, $rejected, $completed] = $this->documentsIn(
            DocumentStatus::Returned,
            DocumentStatus::Rejected,
            DocumentStatus::Completed,
        );

        $page = $this->dashboard(['status' => 'returned']);

        $this->assertSame('returned', $page['filters']['status']);
        $this->assertEqualsCanonicalizing(
            [$returned->id, $rejected->id],
            array_column($page['data'], 'id'),
        );
        $this->assertNotContains($completed->id, array_column($page['data'], 'id'));
    }

    /**
     * A link bookmarked while Rejected was still an option lands on Returned
     * -- where those documents are now -- instead of on an unfiltered list
     * under a blank dropdown.
     */
    public function test_a_bookmarked_rejected_filter_lands_on_returned(): void
    {
        [$returned, $rejected] = $this->documentsIn(
            DocumentStatus::Returned,
            DocumentStatus::Rejected,
            DocumentStatus::Completed,
        );

        $page = $this->dashboard(['status' => 'rejected']);

        $this->assertSame('returned', $page['filters']['status']);
        $this->assertEqualsCanonicalizing(
            [$returned->id, $rejected->id],
            array_column($page['data'], 'id'),
        );
    }

    public function test_the_other_filters_still_find_exactly_their_own_status(): void
    {
        [, , $completed] = $this->documentsIn(
            DocumentStatus::Returned,
            DocumentStatus::Rejected,
            DocumentStatus::Completed,
        );

        $page = $this->dashboard(['status' => 'completed']);

        $this->assertSame([$completed->id], array_column($page['data'], 'id'));
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function dashboard(array $query): array
    {
        return $this->actingAs($this->superAdmin())
            ->get(route('super-admin.dashboard', $query))
            ->assertOk()
            ->viewData('page')['props']['documents'];
    }

    /** @return list<Document> one document per status, in the order given */
    private function documentsIn(DocumentStatus ...$statuses): array
    {
        $office = $this->office('MPDO');
        $clerk = $this->staff($office);

        return array_map(function (DocumentStatus $status) use ($office, $clerk): Document {
            $document = $this->registerDocument($office, $clerk);
            $document->forceFill(['status' => $status->value])->save();

            return $document;
        }, $statuses);
    }
}
