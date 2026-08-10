<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ONE permitted second log table -- see the D1 amendment in
     * docs/implementation/00-architecture.md.
     *
     * document_movements is the audit trail for DOCUMENTS. This table is for
     * events that have no document: logins, failed logins, lockouts, role
     * changes, user CRUD, settings changes, and file downloads. Forcing those
     * into the ledger would pollute the custody timeline with reads.
     *
     * Deliberately narrow: a closed enum vocabulary and a pre-rendered summary
     * string, with NO json column. The moment this grows a `data` blob it
     * becomes the general-purpose activity log D1 exists to prevent.
     *
     * Append-only: created_at but no updated_at.
     */
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Denormalised so the log still reads correctly after the account
            // is renamed or deleted.
            $table->string('actor_label', 191)->nullable();

            $table->string('type', 48);        // App\Enums\SecurityEventType
            $table->string('summary', 255);    // rendered at write time

            // Optional pointer to what was acted on, as a label rather than a
            // polymorphic pair -- morphs() would blow the MySQL 5.7 index limit
            // and buy nothing here.
            $table->string('subject_label', 191)->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 191)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['type', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('created_at');       // retention prune
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};
