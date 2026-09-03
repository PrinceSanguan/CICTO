<?php

namespace App\Actions\Documents;

use App\Enums\DocumentPriority;
use App\Models\Document;
use App\Models\Office;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * §5 Submit Document, sent to several departments AT THE SAME TIME.
 *
 * The flat counterpart to RouteDocument. A routing list has a hierarchy -- a
 * first department, a second, a last -- and every department after the first
 * waits for the one before it. This has none: every department holds the
 * document from the same second, in no order, and no department can block
 * another.
 *
 * ONE DOCUMENT PER DEPARTMENT, and that is the whole design. A single document
 * cannot be in five places at once here: `dm_document_open_unique` permits one
 * open leg, `documents.status` is one column, and §10/§13/§19 are all derived
 * from those two. Five documents need no exception to any of it -- each is an
 * ordinary registration with its own control number, its own deadline from its
 * own type, its own trail, its own copy of the file. What they share is a
 * submission_group_id, which links them on screen and does nothing else.
 *
 * The alternative -- teaching the ledger to hold several custodies at once --
 * is a rewrite of the ledger, the status column, the scan page, the dashboards
 * and every report, and it would invalidate the UAT already collected. See the
 * migration for the full argument.
 */
final class DistributeDocument
{
    public function __construct(private readonly RegisterDocument $register) {}

    /**
     * @param  list<int>  $officeIds  every department getting a copy; at least two
     * @return non-empty-list<Document> one document per department, in the order given
     */
    public function handle(
        string $title,
        int $documentTypeId,
        DocumentPriority $priority,
        array $officeIds,
        User $creator,
        ?string $description = null,
        ?string $remarks = null,
        ?UploadedFile $upload = null,
        ?Request $request = null,
    ): array {
        $officeIds = array_values(array_unique(array_map('intval', $officeIds)));

        if (count($officeIds) < 2) {
            throw new \InvalidArgumentException('Simultaneous distribution needs at least two departments.');
        }

        /*
         * One transaction around the whole submit, so a batch is all or
         * nothing. Half a distribution is the worst outcome available here:
         * three departments hold a document, two never hear about it, and
         * nothing on screen says which two.
         */
        /** @var non-empty-list<Document> */
        return DB::transaction(function () use (
            $title, $documentTypeId, $priority, $officeIds, $creator,
            $description, $remarks, $upload, $request
        ): array {
            $group = (string) Str::uuid();

            $offices = Office::query()->whereKey($officeIds)->get()->keyBy('id');

            return array_map(
                fn (int $officeId): Document => $this->register->handle(
                    title: $title,
                    documentTypeId: $documentTypeId,
                    priority: $priority,
                    // Each copy is registered UNDER its own department, exactly
                    // as a single-department submit is: the department owns the
                    // control number prefix and holds the folder from the
                    // start. Nothing is queued, because nothing is waiting.
                    originatingOffice: $offices->get($officeId) ?? Office::query()->findOrFail($officeId),
                    creator: $creator,
                    description: $description,
                    remarks: $remarks,
                    upload: $upload,
                    submissionGroupId: $group,
                    request: $request,
                ),
                $officeIds,
            );
        });
    }
}
