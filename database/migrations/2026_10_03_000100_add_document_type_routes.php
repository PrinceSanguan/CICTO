<?php

use App\Models\DocumentType;
use App\Support\RouteTemplates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every document type's route, kept in the database and edited on the Super
 * Admin's Document Types page.
 *
 * Client requests: 2026-10-02, "mag dagdag ng documents type tapos i input na
 * rin po don yung offices na dadaanan nya, para sa automation" (approved
 * 2026-10-03), and 2026-10-04, the 43 built-in types editable there too.
 *
 * `is_custom` marks the rows a Super Admin made. The other 43 are
 * DocumentTypeSeeder's, and so is the route each one starts with: the seeder
 * copies it in from App\Support\RouteTemplates. Once a Super Admin edits one,
 * `customized_at` (name, description, turnaround, active) or
 * `route_customized_at` (the route) is set, and from then on the seeder that
 * every deploy re-runs leaves that part alone.
 *
 * A step is one of four kinds -- see App\Enums\RouteStepKind. A custom type's
 * route only ever has `office` steps; the other kinds come from the client's
 * routing PDF and are kept, moved or removed, never invented on the page.
 *
 * A route is a SUGGESTION the Submit form starts from, never a rule: a
 * registered document keeps its own stops in document_route_stops, so editing
 * a route here never moves a document that is already travelling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->boolean('is_custom')->default(false)->after('allows_broadcast');

            // Shown above the route on Submit Document (Travel Order's
            // endorsement, Confidential's "who can see it").
            $table->text('route_note')->nullable()->after('is_custom');

            $table->timestamp('customized_at')->nullable()->after('route_note');
            $table->timestamp('route_customized_at')->nullable()->after('customized_at');
        });

        Schema::create('document_type_route_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_type_id')->constrained()->cascadeOnDelete();

            // 1-based, the order the folder visits after the office filing it.
            $table->unsignedInteger('position');

            $table->string('kind', 16)->default('office');

            // `office` steps only. Restrict, like document_route_stops:
            // offices are deactivated, never deleted, and a step whose office
            // is gone would silently shorten the route.
            $table->foreignId('office_id')->nullable()->constrained('offices')->restrictOnDelete();

            // Left off the route until the person filing ticks it -- or, when
            // `is_checked`, on it until they untick it (the BAC's members).
            $table->boolean('is_optional')->default(false);
            $table->boolean('is_checked')->default(false);

            // `choose` steps: the office offered first (or the filer's own),
            // and the offices it may be; null for any office.
            $table->foreignId('suggested_office_id')->nullable()->constrained('offices')->restrictOnDelete();
            $table->boolean('suggests_origin')->default(false);
            $table->json('only_office_ids')->nullable();

            // `same` steps: the position of the `choose` step it repeats.
            $table->unsignedInteger('same_as_position')->nullable();

            // What happens there; for a `note`, the note itself.
            $table->string('purpose', 191)->nullable();
            $table->timestamps();

            $table->unique(['document_type_id', 'position']);
        });

        // DocumentTypeSeeder does this on every deploy; this is for an
        // installation that migrates without seeding, so the built-in types
        // never reach the Submit form with no route.
        DocumentType::query()
            ->where('is_custom', false)
            ->get()
            ->each(static fn (DocumentType $type) => RouteTemplates::installOriginal($type));
    }

    public function down(): void
    {
        Schema::dropIfExists('document_type_route_steps');

        Schema::table('document_types', function (Blueprint $table) {
            $table->dropColumn(['is_custom', 'route_note', 'customized_at', 'route_customized_at']);
        });
    }
};
