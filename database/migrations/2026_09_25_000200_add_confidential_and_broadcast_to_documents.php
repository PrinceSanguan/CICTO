<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two things from the client's DTS_Office_Routing_Paths.pdf (2026-09-25) that
 * are about who SEES a document rather than where it goes.
 *
 * CONFIDENTIAL -- "City Mayor / HRMO only -- restricted, bypasses normal
 * multi-office routing", and in the PDF's notes, "an access-control flag more
 * than a workflow type". The type says which documents are confidential; the
 * document carries its own copy, stamped when it is filed, so the rule that
 * hides it is a column on the row every list already reads.
 *
 * NOT BACKFILLED. A Confidential document filed before today may be sitting
 * at an office outside the City Mayor and HRMO, and hiding it there would
 * leave it on a desk nobody can see. Only documents filed from now on are
 * restricted.
 *
 * BROADCAST -- "Broadcast to ALL offices" on an Executive Order and a
 * Memorandum Circular. The folder does not move: every office is told and may
 * open it. When, and by whom, is kept on the document.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->boolean('is_confidential')->default(false)->after('requires_approval');
            $table->boolean('allows_broadcast')->default(false)->after('is_confidential');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->boolean('is_confidential')->default(false)->after('priority')->index();
            $table->timestamp('broadcast_at')->nullable()->after('completed_at')->index();
            $table->foreignId('broadcast_by_id')->nullable()->after('broadcast_at')
                ->constrained('users')->nullOnDelete();
        });

        // DocumentTypeSeeder sets both on every deploy; this is for an
        // installation that migrates without seeding.
        DB::table('document_types')->where('code', 'CONFIDENTIAL')->update(['is_confidential' => true]);
        DB::table('document_types')->whereIn('code', ['EXEC-ORDER', 'MEMO-CIRCULAR'])->update(['allows_broadcast' => true]);
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('broadcast_by_id');
            $table->dropIndex(['is_confidential']);
            $table->dropIndex(['broadcast_at']);
            $table->dropColumn(['is_confidential', 'broadcast_at']);
        });

        Schema::table('document_types', function (Blueprint $table) {
            $table->dropColumn(['is_confidential', 'allows_broadcast']);
        });
    }
};
