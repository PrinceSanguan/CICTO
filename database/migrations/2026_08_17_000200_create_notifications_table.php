<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deliberately NOT Laravel's database-channel notifications table.
     *
     * The stock migration uses $table->morphs(), which emits varchar(255); a
     * composite index over that blows the MySQL 5.7 utf8mb4 767-byte key limit
     * on the client's host rather than in CI. Its json `data` column is worse --
     * whereJsonContains compiles differently per driver, and the first thing
     * anyone wants is "notifications for document X".
     *
     * So: real foreign keys, and display strings rendered at write time. A
     * notification records what was true when it fired.
     *
     * Index key math: bigint 8 + varchar(64) utf8mb4 256 = 264 bytes, well
     * inside the limit.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_movement_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->string('type', 32);            // App\Enums\NotificationType
            $table->string('dedupe_key', 64);      // 'forwarded:1841' | 'overdue:1841:2026-08-09'
            $table->string('title', 191);
            $table->string('body', 255)->nullable();
            $table->string('control_number', 40);  // denormalised: the dropdown joins nothing
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // Idempotency lives on dedupe_key rather than the movement FK, so
            // "overdue once per leg" versus "once per day" is a string change
            // rather than a migration.
            $table->unique(['user_id', 'dedupe_key'], 'notifications_user_dedupe_unique');
            $table->index(['user_id', 'read_at', 'id'], 'notifications_user_unread_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
