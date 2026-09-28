<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The 4-digit Security PIN asked for before a document is shown (client
 * request, 2026-09-25).
 *
 * Stored as a HASH, never the digits: a PIN is four characters from ten, so the
 * column is the whole secret, and a database dump must not hand it over.
 * Nullable because every existing account starts without one -- the first time
 * such a user opens a document, they are asked to create it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('security_pin')->nullable()->after('password');
            $table->timestamp('security_pin_set_at')->nullable()->after('security_pin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['security_pin', 'security_pin_set_at']);
        });
    }
};
