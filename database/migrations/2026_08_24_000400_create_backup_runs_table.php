<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §22. The visible history a backup system needs to be trustworthy.
     *
     * Listing files on a disk cannot record a run that FAILED, how long it
     * took, who started it, or whether anyone has ever restored one -- which is
     * why this table exists rather than leaning on the filesystem.
     */
    public function up(): void
    {
        Schema::create('backup_runs', function (Blueprint $table) {
            $table->id();

            $table->string('kind', 16)->default('database');  // database|full
            $table->string('status', 16)->default('running'); // running|completed|failed

            // Which dumper actually ran. The host decides this at runtime, so
            // recording it is the difference between "the backup worked" and
            // "the backup worked, here, that day, this way".
            $table->string('driver', 16)->nullable();         // shell|php

            $table->string('disk', 32)->nullable();
            $table->string('path', 500)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('checksum_sha256', 64)->nullable();
            $table->boolean('is_encrypted')->default(false);

            // A PHP dump emits DATA, not DDL, so it is only restorable against
            // the migration set it was taken from. Recording that is what makes
            // the restore runbook executable months later.
            $table->string('last_migration', 191)->nullable();

            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            // §22 is "Backup AND Recovery". Until this is non-null nobody has
            // ever proven the backups restore, and the UI says so in red.
            $table->timestamp('restored_at')->nullable();
            $table->text('restore_notes')->nullable();

            $table->text('error')->nullable();
            $table->foreignId('triggered_by_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['status', 'started_at']);
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
    }
};
