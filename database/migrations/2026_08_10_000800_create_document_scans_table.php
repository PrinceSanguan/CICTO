<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §7: who scanned this folder, where, and when.
     *
     * A scan is a LOOKUP, not a transfer, so it deliberately lives outside
     * document_movements. Putting scans in the ledger would flood the audit
     * trail with courier noise and break the one-open-leg invariant. This is the
     * one place the single-ledger rule does not apply, and it is an exception on
     * purpose rather than a slip.
     *
     * ip_address and user_agent are personal information under RA 10173, so this
     * table has a retention policy (config/cicto.php) and a privacy notice.
     */
    public function up(): void
    {
        Schema::create('document_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // null = courier
            $table->foreignId('office_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 16)->default('camera'); // camera|wedge|manual
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 191)->nullable();
            $table->timestamp('scanned_at');
            $table->timestamps();

            $table->index(['document_id', 'scanned_at']);
            $table->index('scanned_at');                     // retention prune
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_scans');
    }
};
