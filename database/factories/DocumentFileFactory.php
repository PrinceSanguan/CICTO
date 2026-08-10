<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentFile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DocumentFile>
 */
class DocumentFileFactory extends Factory
{
    protected $model = DocumentFile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'version' => 1,
            'disk' => 'documents',
            'path' => 'documents/1/'.Str::ulid().'.pdf',
            'original_name' => $this->faker->word().'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => $this->faker->numberBetween(10_000, 2_000_000),
            'checksum_sha256' => hash('sha256', Str::random(32)),
            'uploaded_by_id' => User::factory(),
        ];
    }
}
