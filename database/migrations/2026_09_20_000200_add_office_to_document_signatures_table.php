<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §15: a signature belongs to an OFFICE, not only to a person.
     *
     * The client's rule of 2026-09-20 is "only one sign per office". Until now
     * the register could not answer that question reliably: `signer_office` is
     * a snapshotted NAME, kept so a certificate still reads correctly after an
     * office is renamed, and matching on it would make a rename silently
     * reopen signing for an office that had already signed.
     *
     * So the office is recorded twice, on purpose, and the two are not
     * interchangeable:
     *
     *   - `signer_office`  the name AS IT WAS, for the printed certificate;
     *   - `office_id`      who it was, for the rule.
     *
     * Nullable, and it will stay null for two real cases: a Super Admin, who
     * belongs to no office and therefore signs as themselves, and every
     * signature made before today. SignDocument reads it as "no office" rather
     * than as "some office", so neither case can collide with a real one.
     *
     * NO UNIQUE INDEX. "One per office" is not "one per office, ever": a
     * corrected file has to be signable again by the same office, and
     * SignDocument counts from the last version somebody UPLOADED for exactly
     * that reason. A database constraint cannot see that baseline, and one
     * that ignored it would refuse the second signature on a document that had
     * genuinely changed.
     */
    public function up(): void
    {
        Schema::table('document_signatures', function (Blueprint $table) {
            $table->foreignId('office_id')->nullable()->after('user_id')
                ->constrained('offices')->nullOnDelete();

            // The rule's own lookup: "has this office signed this document for
            // this purpose?" -- asked once per signing attempt.
            $table->index(['document_id', 'office_id', 'purpose'], 'doc_sig_document_office_purpose_index');
        });
    }

    public function down(): void
    {
        Schema::table('document_signatures', function (Blueprint $table) {
            $table->dropIndex('doc_sig_document_office_purpose_index');
            $table->dropConstrainedForeignId('office_id');
        });
    }
};
