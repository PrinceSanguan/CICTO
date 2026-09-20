<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §15, extended on 2026-09-20: the mark may now be PRINTED ONTO THE PAGE.
     *
     * The client asked for a signature that looks like ink on the paper rather
     * than a record filed beside it. That reverses the note the original
     * migration and DocumentSignatureController both carried -- "nothing is
     * embedded into the PDF itself" -- so say plainly what replaced it.
     *
     * WHAT IS STORED, AND WHY EACH PIECE.
     *
     * `stamp_page` and the four `stamp_*` fractions are WHERE the signer put
     * the mark, as a proportion of the page rather than in points. Fractions
     * survive a page box the viewer reports differently, and they are the only
     * record of placement that outlives the produced file: delete the stamped
     * version under the retention policy and the register can still say the
     * mark sat two thirds down page 3.
     *
     * `stamped_file_id` is the version the stamping PRODUCED. It is a separate
     * column from `document_file_id`, which stays what it always was: the
     * version the signer READ and bound their hash to. Collapsing the two
     * would make the hash cover a file that contains the signature attesting
     * to it, and the tamper check would then be verifying its own output.
     *
     * Every column is nullable, because three real cases have no stamp: typed
     * signatures, a document whose current version is not a PDF (Word and
     * Excel are accepted uploads with no page to draw on), and every signature
     * made before today.
     */
    public function up(): void
    {
        Schema::table('document_signatures', function (Blueprint $table) {
            // 1-based, matching what the signer saw in the viewer.
            $table->unsignedSmallInteger('stamp_page')->nullable()->after('method');

            /*
             * Fractions of the page box, with the origin at the TOP-LEFT of the
             * page as displayed -- i.e. after the page's own /Rotate is
             * applied, which is what the signer was looking at. PDF user space
             * has its origin at the bottom-left and ignores /Rotate, so the
             * conversion happens once, in the browser, at stamping time.
             *
             * decimal(7,6) holds 0.000000-1.000000 exactly. A float would not:
             * these are compared and re-rendered, and "0.30000000000000004"
             * printed on an audit page is noise nobody can act on.
             */
            $table->decimal('stamp_x', 7, 6)->nullable()->after('stamp_page');
            $table->decimal('stamp_y', 7, 6)->nullable()->after('stamp_x');
            $table->decimal('stamp_width', 7, 6)->nullable()->after('stamp_y');
            $table->decimal('stamp_height', 7, 6)->nullable()->after('stamp_width');

            /*
             * nullOnDelete, not cascade. Purging the stamped version under the
             * retention policy must not delete the signature -- the attestation
             * happened, and the certificate still has to print.
             */
            $table->foreignId('stamped_file_id')->nullable()->after('document_file_id')
                ->constrained('document_files')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('document_signatures', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stamped_file_id');
            $table->dropColumn([
                'stamp_page',
                'stamp_x',
                'stamp_y',
                'stamp_width',
                'stamp_height',
            ]);
        });
    }
};
