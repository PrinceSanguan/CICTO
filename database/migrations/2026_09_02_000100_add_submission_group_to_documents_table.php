<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ties together the documents produced by ONE submit sent to several
     * departments at the same time.
     *
     * §5 can now distribute two ways. "One after another" is a routing list --
     * one document, one folder, visiting departments in order (see
     * document_route_stops). "All at the same time" is the opposite shape: no
     * hierarchy, no queue, every department holding its own copy from the first
     * second, each with its own control number, deadline and trail.
     *
     * That second shape CANNOT be one document. `dm_document_open_unique`
     * allows a document exactly one open leg, `documents.status` is a single
     * column, and the public scan page has one "Currently at" line -- a
     * document held by five departments at once has five answers to every one
     * of those, and every figure in §10, §13 and §19 is derived from them. So
     * simultaneous distribution registers one document PER department instead,
     * which needs no exception to any of it: five ordinary documents, five
     * ordinary ledgers.
     *
     * This column is the only thing that remembers they were one submission.
     * Null for the overwhelming majority of documents -- anything submitted to
     * a single department, or routed in order -- and never used to authorise
     * anything: it is a display link, so the page can say "submitted at the
     * same time as these", and nothing more.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->uuid('submission_group_id')->nullable()->after('created_by_id');

            // "the rest of this submission", the only read there is.
            $table->index('submission_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['submission_group_id']);
            $table->dropColumn('submission_group_id');
        });
    }
};
