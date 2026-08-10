<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gapless, race-free per-office/per-year counter for control numbers.
     * Allocated under SELECT ... FOR UPDATE inside the registration
     * transaction -- never max(id)+1, never count()+1.
     */
    public function up(): void
    {
        Schema::create('document_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();

            // period_year, not year: YEAR is a MySQL type keyword and reads
            // badly in hand-written diagnostic SQL.
            $table->unsignedSmallInteger('period_year');

            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['office_id', 'period_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_number_sequences');
    }
};
