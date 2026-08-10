<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §15 digital signatures.
     *
     * This is an ELECTRONIC signature, not PKI: no certificate authority, no
     * certificate chain, no revocation, no PAdES embedding. The schema does not
     * pretend otherwise -- there is nowhere to put a certificate because there
     * isn't one.
     *
     * What it does record is who signed, in what capacity, when, from where,
     * and -- critically -- a hash of the exact file version they signed, so a
     * later substitution is detectable.
     */
    public function up(): void
    {
        Schema::create('document_signatures', function (Blueprint $table) {
            $table->id();

            // Public verification handle. Unguessable, like the QR token, and
            // printed on the Signature Certificate.
            $table->string('serial', 26)->unique();

            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_movement_id')->nullable()
                ->constrained()->nullOnDelete();

            // The EXACT version signed. Null only if the document had no file.
            $table->foreignId('document_file_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            // Point-in-time snapshot. A signature is an attestation made by a
            // person in a role on a date; it has to survive that person later
            // being renamed, transferred, or demoted.
            $table->string('signer_name', 191);
            $table->string('signer_position', 191)->nullable();
            $table->string('signer_office', 191)->nullable();

            $table->string('purpose', 32)->default('approval');
            $table->string('method', 16)->default('drawn');   // drawn|typed

            // Drawn signatures are PNG on the private disk; typed ones have no
            // image and render from signer_name.
            $table->string('image_disk', 32)->nullable();
            $table->string('image_path', 500)->nullable();

            // sha256 of the signed file's bytes, copied from document_files at
            // signing time so a later row edit cannot quietly re-point it.
            $table->string('document_hash_sha256', 64)->nullable();

            // sha256 over a versioned canonical payload. Deliberately NOT an
            // HMAC keyed on APP_KEY: that would make every signature
            // unverifiable if the key is ever rotated or lost, widening a blast
            // radius the backup runbook already warns about.
            $table->string('signature_hash_sha256', 64)->unique();

            $table->string('ip_address', 45)->nullable();
            $table->timestamp('signed_at');
            $table->timestamps();

            // One signature per person per file version per purpose. Signing a
            // NEW version is a new row -- "superseded" is derived from version
            // order, never stored.
            $table->unique(
                ['document_file_id', 'user_id', 'purpose'],
                'doc_sig_file_user_purpose_unique',
            );

            $table->index(['document_id', 'signed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_signatures');
    }
};
