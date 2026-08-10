<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A child table with `version` from line one -- never a path column on
     * documents. This is what makes Phase 3 Version Control a number and a
     * history panel instead of a data migration.
     *
     * Rows are immutable and append-only. There is no is_current flag: the
     * current file is MAX(version), resolved by latestOfMany('version'). A
     * boolean would need a second write on every upload and is the classic
     * source of "two current files".
     */
    public function up(): void
    {
        Schema::create('document_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');

            $table->string('disk', 32)->default('documents'); // private disk, never 'public'
            $table->string('path', 500);                      // documents/{id}/{ulid}.pdf
            $table->string('original_name', 255);
            $table->string('mime_type', 127);
            $table->unsignedBigInteger('size_bytes');
            $table->string('checksum_sha256', 64);

            $table->foreignId('uploaded_by_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('document_movement_id')->nullable()
                ->constrained()->nullOnDelete();              // the leg it was uploaded during
            $table->string('replace_reason', 500)->nullable();

            // Phase 3 retention: bytes may be pruned while the metadata row stays,
            // so the version history never develops holes.
            $table->timestamp('purged_at')->nullable();

            $table->timestamps();

            $table->unique(['document_id', 'version']);
            $table->index('checksum_sha256');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_files');
    }
};
