<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ROUTING PLAN. One row = one office still to be visited.
     *
     * This is NOT the ledger. document_movements records where the folder has
     * actually been; this records where it is going next, and nothing here is
     * an audit record. The two must not be merged, and the reason is precise:
     * openMovement(), DocumentBuilder::heldByOffice(), DocumentMovement::open()
     * and TransitionDocument's lock query all select on `departed_at IS NULL`,
     * NOT on `is_open`. Pre-inserting future legs as movement rows would
     * therefore satisfy openMovement() while dm_document_open_unique caught
     * nothing -- and DocumentBuilder::visibleTo() would hand every queued office
     * read access to a document that has not reached them.
     *
     * So the one-open-leg invariant survives untouched: a five-office route is
     * five ordinary forwards issued by the system instead of by five clicks,
     * and the trail it leaves is byte-for-byte the shape a clerk produces by
     * hand today.
     */
    public function up(): void
    {
        Schema::create('document_route_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();

            // Monotonic per document and never reused, so a second route
            // started after the first finished cannot collide with it. Order
            // within a route is the ascending order of this column.
            $table->unsignedInteger('position');

            $table->foreignId('office_id')->constrained('offices')->restrictOnDelete();

            // App\Enums\RouteStopStatus. 'pending' rows are the queue; the rest
            // are kept rather than deleted so the document page can show what
            // the route WAS after a rejection cancelled the remainder.
            $table->string('status', 16);

            $table->foreignId('created_by_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // When this stop stopped being pending, whichever way it resolved.
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->unique(['document_id', 'position']);

            // "what is still queued for this document", the only hot read.
            $table->index(['document_id', 'status', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_route_stops');
    }
};
