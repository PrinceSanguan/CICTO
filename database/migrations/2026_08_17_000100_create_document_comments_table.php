<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §16 comments AND §9 approval remarks -- one table, one panel.
     *
     * Approving with a remark writes two rows in one transaction: the comment
     * (context='approval', document_movement_id set) and the movement's
     * `remarks` holding the same text. The comment is what the panel renders;
     * the movement's remarks is the immutable ledger copy. Approval comments
     * have no edit route, so the two cannot diverge.
     */
    public function up(): void
    {
        Schema::create('document_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('document_movement_id')->nullable()
                ->constrained()->nullOnDelete();          // set when this is a decision remark
            $table->foreignId('parent_id')->nullable()
                ->constrained('document_comments')->cascadeOnDelete();

            $table->string('context', 16)->default('comment'); // comment|approval|rejection|return
            $table->text('body');
            $table->boolean('is_internal')->default(false);    // hidden from the submitter
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();

            $table->index(['document_id', 'created_at']);
            $table->index(['document_movement_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_comments');
    }
};
