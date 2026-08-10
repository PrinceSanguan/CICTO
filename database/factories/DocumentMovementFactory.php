<?php

namespace Database\Factories;

use App\Enums\MovementAction;
use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\Office;
use App\Models\User;
use App\Support\Deadlines;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentMovement>
 */
class DocumentMovementFactory extends Factory
{
    protected $model = DocumentMovement::class;

    /**
     * Defaults to a CLOSED leg. An open leg competes with the
     * unique(document_id, is_open) constraint, so opening one has to be
     * deliberate -- use ->open().
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $arrived = Deadlines::now()->subDays(2);

        return [
            'document_id' => Document::factory(),
            'sequence' => 1,
            'from_office_id' => null,
            'to_office_id' => Office::factory(),
            'actor_id' => User::factory(),
            'action' => MovementAction::Registered,
            'arrived_at' => $arrived,
            'departed_at' => $arrived->addDay(),
            'is_open' => null,
        ];
    }

    public function open(): static
    {
        return $this->state(fn () => [
            'departed_at' => null,
            'is_open' => 1,
        ]);
    }
}
