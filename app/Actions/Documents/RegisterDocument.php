<?php

namespace App\Actions\Documents;

use App\Enums\DocumentPriority;
use App\Enums\DocumentStatus;
use App\Enums\MovementAction;
use App\Enums\RouteStopStatus;
use App\Events\DocumentTransitioned;
use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\DocumentRouteStop;
use App\Models\DocumentType;
use App\Models\Office;
use App\Models\User;
use App\Support\Deadlines;
use App\Support\QrToken;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Spec §5: the Submit Document form, end to end.
 *
 * Sequence, document, genesis movement and first file are one transaction, so a
 * failed upload never burns a control number.
 */
final class RegisterDocument
{
    public function __construct(
        private readonly AllocateControlNumber $allocateControlNumber,
        private readonly StoreDocumentFile $storeDocumentFile,
    ) {}

    /**
     * Explicit parameters rather than a loose array: every one of these is a
     * §5 form field, and a typed signature is what stops a validated payload
     * being reshaped somewhere between the request and the insert.
     *
     * @param  list<int>  $routeOfficeIds  the departments to visit AFTER the
     *                                     originating one, in visiting order
     * @param  string|null  $submissionGroupId  set only by DistributeDocument,
     *                                          to link the copies one submit made
     */
    public function handle(
        string $title,
        int $documentTypeId,
        DocumentPriority $priority,
        Office $originatingOffice,
        User $creator,
        ?string $description = null,
        ?string $remarks = null,
        ?UploadedFile $upload = null,
        array $routeOfficeIds = [],
        ?string $submissionGroupId = null,
        ?Request $request = null,
    ): Document {
        $routeOfficeIds = array_values(array_unique(array_map('intval', $routeOfficeIds)));

        return DB::transaction(function () use (
            $title, $documentTypeId, $priority, $originatingOffice, $creator,
            $description, $remarks, $upload, $routeOfficeIds, $submissionGroupId, $request
        ): Document {
            $type = DocumentType::query()->findOrFail($documentTypeId);
            $now = Deadlines::now();

            $document = new Document;
            $document->forceFill([
                'control_number' => $this->allocateControlNumber->handle($originatingOffice, $now),
                'qr_token' => $this->uniqueQrToken(),
                'title' => $title,
                'description' => $description,
                'remarks' => $remarks,
                'document_type_id' => $type->id,
                'originating_office_id' => $originatingOffice->id,
                'created_by_id' => $creator->id,
                'submission_group_id' => $submissionGroupId,
                'status' => DocumentStatus::Initiated->value,
                'priority' => $priority->value,

                // Stamped once, at registration, and immutable thereafter: the
                // completion window quoted to a citizen must never silently
                // shift because the document was routed somewhere slow.
                'due_at' => Deadlines::dueAt($type, $now),
            ])->save();

            // The genesis leg. Written even though nothing reads it until
            // Phase 2 -- it is what exercises the ledger before five features
            // stack on top of it, and it is why "how long did this sit in the
            // originating office" is answerable at all.
            $movement = DocumentMovement::create([
                'document_id' => $document->id,
                'sequence' => 1,
                'from_office_id' => null,
                'to_office_id' => $originatingOffice->id,
                'actor_id' => $creator->id,
                'action' => MovementAction::Registered->value,
                'from_status' => null,
                'to_status' => DocumentStatus::Initiated->value,
                'arrived_at' => $now,
                'departed_at' => null,
                'due_at' => Deadlines::legDueAt($type, $document->due_at, $now),
                'is_open' => 1,
                'ip_address' => $request?->ip(),
                'user_agent' => mb_substr((string) $request?->userAgent(), 0, 191) ?: null,
            ]);

            /*
             * §5's Department field, when the submitter picked more than one.
             *
             * The rest of the picks are a ROUTING PLAN, not extra custodies:
             * position 1 is the first place the folder still has to go, and
             * AdvanceRoute moves it there when the originating office approves.
             * Written in the same transaction as the document, so a route can
             * never survive a registration that rolled back -- and so a failed
             * stop insert costs a control number rather than leaving a document
             * queued for departments nobody chose.
             */
            $position = 1;

            foreach ($routeOfficeIds as $officeId) {
                DocumentRouteStop::create([
                    'document_id' => $document->id,
                    'position' => $position++,
                    'office_id' => $officeId,
                    'status' => RouteStopStatus::Pending,
                    'created_by_id' => $creator->id,
                ]);
            }

            if ($upload instanceof UploadedFile) {
                $this->storeDocumentFile->handle($document, $upload, $creator, $movement);
            }

            // §12's first trigger, "newly assigned". Registration is a ledger
            // write like any other, so it goes through the same event rather
            // than growing a second notification path.
            DocumentTransitioned::dispatch($document, $movement, MovementAction::Registered, $creator);

            return $document->refresh();
        });
    }

    /**
     * 130 bits of randomness makes a collision effectively impossible, but the
     * unique index is the real guarantee -- this loop just avoids surfacing a
     * constraint violation to the clerk.
     */
    private function uniqueQrToken(): string
    {
        do {
            $token = QrToken::generate();
        } while (Document::query()->where('qr_token', $token)->exists());

        return $token;
    }
}
