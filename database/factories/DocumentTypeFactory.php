<?php

namespace Database\Factories;

use App\Models\DocumentType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentType>
 */
class DocumentTypeFactory extends Factory
{
    protected $model = DocumentType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => mb_strtoupper($this->faker->unique()->lexify('????')),
            'name' => $this->faker->words(2, true),
            'turnaround_days' => 3,
            'requires_approval' => true,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
