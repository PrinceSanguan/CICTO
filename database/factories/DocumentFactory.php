<?php

namespace Database\Factories;

use App\Enums\DocumentPriority;
use App\Enums\DocumentStatus;
use App\Enums\MovementAction;
use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\DocumentType;
use App\Models\Office;
use App\Models\User;
use App\Support\Deadlines;
use App\Support\QrToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $office = Office::factory();

        return [
            'control_number' => mb_strtoupper($this->faker->unique()->bothify('???-2026-#####')),
            'qr_token' => QrToken::generate(),
            'title' => $this->faker->sentence(4),
            'description' => $this->faker->paragraph(),
            'remarks' => null,
            'document_type_id' => DocumentType::factory(),
            'originating_office_id' => $office,
            'created_by_id' => User::factory(),
            'status' => DocumentStatus::Initiated,
            'priority' => DocumentPriority::Normal,
            'due_at' => Deadlines::now()->addDays(3),
        ];
    }

    /**
     * A document as RegisterDocument would leave it: with its genesis movement,
     * so the ledger invariant holds and openMovement resolves.
     *
     * Prefer this over the bare factory in any test that touches routing,
     * status tracking or the audit trail.
     */
    public function registered(?Office $office = null): static
    {
        return $this->afterCreating(function (Document $document) use ($office): void {
            DocumentMovement::create([
                'document_id' => $document->id,
                'sequence' => 1,
                'from_office_id' => null,
                'to_office_id' => $office->id ?? $document->originating_office_id,
                'actor_id' => $document->created_by_id,
                'action' => MovementAction::Registered,
                'from_status' => null,
                'to_status' => $document->status,
                'arrived_at' => $document->created_at ?? Deadlines::now(),
                'departed_at' => null,
                'due_at' => $document->due_at,
                'is_open' => 1,
            ]);
        });
    }

    public function overdue(): static
    {
        return $this->state(fn () => ['due_at' => Deadlines::now()->subDays(2)]);
    }

    public function approaching(): static
    {
        return $this->state(fn () => ['due_at' => Deadlines::now()->addDay()]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => DocumentStatus::Completed,
            'completed_at' => Deadlines::now(),
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['archived_at' => Deadlines::now()]);
    }
}
