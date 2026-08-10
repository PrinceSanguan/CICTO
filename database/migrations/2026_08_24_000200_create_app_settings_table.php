<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §2 "Super Admin ... configures system settings".
     *
     * setting_key / setting_value / group_name rather than key / value / group:
     * KEY and GROUP are reserved words in MySQL. Laravel quotes identifiers so
     * it would work, but every hand-written diagnostic query against the table
     * would then fail, which is exactly when you least want a syntax error.
     *
     * setting_value is TEXT because it holds ciphertext -- the model casts it
     * `encrypted` unconditionally rather than carrying a per-row is_encrypted
     * flag somebody has to remember to set. This is where SMTP credentials and
     * the backup passphrase live.
     */
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('setting_key', 100)->primary();
            $table->text('setting_value')->nullable();
            $table->string('value_type', 16)->default('string'); // string|int|bool|json
            $table->string('group_name', 32)->default('general');
            $table->boolean('is_secret')->default(false);        // hide the value in the UI
            $table->string('label', 191)->nullable();
            $table->timestamps();

            $table->index('group_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
