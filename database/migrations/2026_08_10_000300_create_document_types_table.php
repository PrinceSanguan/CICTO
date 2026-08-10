<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();            // 'MEMO', 'PR', 'DV', 'TO'
            $table->string('name', 191);
            $table->string('description', 500)->nullable();

            // Spec §11's SLA source. Living here means deadline monitoring needs
            // no migration of its own in Phase 2.
            $table->unsignedSmallInteger('turnaround_days')->nullable();

            $table->boolean('requires_approval')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_types');
    }
};
