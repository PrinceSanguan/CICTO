<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * THE LEDGER. One row = one leg = one period of custody.
     *
     * This single table is both the routing record (§9) and the audit trail
     * (§13), and it is the read substrate for status tracking (§10), approvals
     * (§9) and deadlines (§11). There is no activity_log, no
     * document_status_history and no approvals table.
     *
     * Ships in its final column shape in Phase 1 even though Phases 2-4 are what
     * read most of it -- retrofitting this table mid-project is a data migration
     * against live client data.
     */
    public function up(): void
    {
        Schema::create('document_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');            // 1-based ordinal within the document

            $table->foreignId('from_office_id')->nullable()
                ->constrained('offices')->restrictOnDelete();
            $table->foreignId('to_office_id')->nullable()
                ->constrained('offices')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->string('action', 32);                   // App\Enums\MovementAction
            $table->string('from_status', 32)->nullable();  // DocumentStatus before
            $table->string('to_status', 32)->nullable();    // DocumentStatus after
            $table->text('remarks')->nullable();            // immutable ledger copy of the comment body

            $table->timestamp('arrived_at')->nullable();    // custody began
            $table->timestamp('departed_at')->nullable();   // custody ended; NULL = still holding it
            $table->timestamp('due_at')->nullable();        // per-leg SLA

            // is_open carries a constraint, nothing more. departed_at remains the
            // semantic truth. Both MySQL and PostgreSQL allow MANY NULLs inside a
            // unique index, so unique(document_id, is_open) enforces "at most one
            // open leg per document" in the database on both drivers -- without a
            // PostgreSQL partial index, which has no MySQL equivalent.
            $table->unsignedTinyInteger('is_open')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 191)->nullable();

            $table->timestamps();

            $table->unique(['document_id', 'sequence']);
            $table->unique(['document_id', 'is_open'], 'dm_document_open_unique');

            $table->index(['to_office_id', 'is_open']);      // "what is sitting in my office right now"
            $table->index(['to_office_id', 'document_id']);  // visibleTo() EXISTS predicate
            $table->index(['from_office_id', 'document_id']); // visibleTo() EXISTS predicate
            $table->index(['action', 'created_at']);         // §19 approvals-per-month
            $table->index(['actor_id', 'created_at']);       // §19 user activity report
            $table->index(['arrived_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_movements');
    }
};
