<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();

            $table->string('control_number', 40)->unique();   // MPDO-2026-00042

            // Unguessable, and NOT the control number: control numbers are
            // sequential, so a QR encoding one makes the whole register
            // enumerable by incrementing. string(), never char() -- PostgreSQL
            // blank-pads char(n) and equality then silently fails on tokens.
            $table->string('qr_token', 26)->unique();

            $table->string('title', 191);
            $table->text('description')->nullable();
            $table->text('remarks')->nullable();              // §5: distinct from description

            $table->foreignId('document_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('originating_office_id')->constrained('offices')->restrictOnDelete();
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();

            $table->string('status', 32)->default('initiated'); // App\Enums\DocumentStatus
            $table->string('priority', 16)->default('normal');  // App\Enums\DocumentPriority

            // §11 deadlines -- columns ship now, the feature ships in Phase 2.
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // §12 notification sweep idempotency -- Phase 2 writes these.
            $table->timestamp('deadline_warned_at')->nullable();
            $table->timestamp('overdue_notified_at')->nullable();

            // §20 archive -- columns ship now, the feature ships in Phase 4.
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('archive_reason', 500)->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['status', 'priority']);
            $table->index(['archived_at', 'status']);
            $table->index(['due_at']);
            $table->index(['completed_at']);
            $table->index(['originating_office_id', 'created_at']);
            $table->index(['document_type_id', 'created_at']);
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
