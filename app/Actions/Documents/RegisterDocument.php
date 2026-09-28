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
use App\Support\Confidential;
use App\Support\Deadlines;
use App\Support\QrToken;
use App\Support\RoutePlan;
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
        private readonly TransitionDocument $transition,
    ) {}

    /**
     * Explicit parameters rather than a loose array: every one of these is a
     * §5 form field, and a typed signature is what stops a validated payload
     * being reshaped somewhere between the request and the insert.
     *
     * @param  list<int>  $routeOfficeIds  the departments to visit AFTER the
     *                                     originating one, in visiting order
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
        ?Request $request = null,
    ): Document {
        /*
         * Repeats are kept, except straight after themselves. A route may come
         * back to an office -- the route templates of 2026-09-25 send a
         * Disbursement Voucher to Treasury twice -- but a stop at the desk the
         * folder is already on, the originating one included, is not a stop.
         */
        $routeOfficeIds = array_slice(RoutePlan::collapse([
            $originatingOffice->id,
            ...array_map('intval', $routeOfficeIds),
        ]), 1);

        return DB::transaction(function () use (
            $title, $documentTypeId, $priority, $originatingOffice, $creator,
            $description, $remarks, $upload, $routeOfficeIds, $request
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
                'status' => DocumentStatus::Initiated->value,
                'priority' => $priority->value,

                // Stamped from the type once, like due_at: who may see a
                // document must not change because a type was edited later.
                'is_confidential' => $type->is_confidential,

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
            if ($type->is_confidential) {
                $this->checkConfidentialRoute($originatingOffice, $routeOfficeIds);
            }

            $position = 1;

            // A Confidential document has no route: it is sent on below.
            foreach ($type->is_confidential ? [] : $routeOfficeIds as $officeId) {
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

            /*
             * CONFIDENTIAL "bypasses normal multi-office routing" (client,
             * 2026-09-25). It goes straight on to the City Mayor or HRMO as
             * part of being filed, sent by the person filing it -- so nobody
             * else at the office it was filed from ever has it on their desk,
             * or in their list, or has to receive it to let it go.
             */
            if ($type->is_confidential && $routeOfficeIds !== []) {
                $this->transition->handle(
                    document: $document,
                    action: MovementAction::Forwarded,
                    actor: $creator,
                    toOfficeId: $routeOfficeIds[0],
                    expectedMovementId: $movement->id,
                    request: $request,
                );
            }

            return $document->refresh();
        });
    }

    /**
     * Where a Confidential document may go: to ONE of the City Mayor and HRMO,
     * or nowhere when it is filed at one of them. StoreDocumentRequest says so
     * in words; this is for every other caller, so the rule does not depend on
     * which door a document came in by.
     *
     * @param  list<int>  $routeOfficeIds
     */
    private function checkConfidentialRoute(Office $origin, array $routeOfficeIds): void
    {
        $ends = $routeOfficeIds === [] ? $origin->id : $routeOfficeIds[0];

        if (count($routeOfficeIds) > 1 || ! Confidential::trusts($ends)) {
            throw new \InvalidArgumentException('A Confidential document goes to '.Confidential::officeNames().' only, and to one of them.');
        }
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
