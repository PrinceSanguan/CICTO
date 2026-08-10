<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * No ->after(): it is MySQL-only, and PostgreSQL and SQLite ignore it
     * silently, so column order diverges between environments. The inherited
     * two-factor migration uses it; nothing new does.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 32)->default('user');   // App\Enums\Role
            $table->foreignId('office_id')->nullable()
                ->constrained('offices')->nullOnDelete();
            $table->string('position', 191)->nullable();
            $table->string('phone', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();

            $table->index(['role', 'office_id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role', 'office_id']);
            $table->dropConstrainedForeignId('office_id');
            $table->dropColumn(['role', 'position', 'phone', 'is_active', 'last_login_at']);
        });
    }
};
