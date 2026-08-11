<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user preferences for §4's Settings screen.
 *
 * A json column rather than four typed ones: these are display preferences
 * whose set will change as the panel grows, and each addition would otherwise
 * be a migration against a live register. Nothing here is ever queried or
 * joined on -- it is read once per request for the user already in hand -- so
 * the usual objection to storing structured data in json does not apply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nullable, not default '{}': the three drivers disagree about
            // defaults on json/text columns, and null reads the same on all of
            // them.
            $table->json('preferences')->nullable()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('preferences');
        });
    }
};
