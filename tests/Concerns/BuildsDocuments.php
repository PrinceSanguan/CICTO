<?php

namespace Tests\Concerns;

use App\Actions\Documents\RegisterDocument;
use App\Enums\DocumentPriority;
use App\Enums\Role;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Office;
use App\Models\User;

trait BuildsDocuments
{
    protected function office(string $code = 'MPDO', string $name = 'Planning Office'): Office
    {
        return Office::factory()->create(['code' => $code, 'name' => $name]);
    }

    protected function documentType(int $turnaroundDays = 5): DocumentType
    {
        return DocumentType::factory()->create(['turnaround_days' => $turnaroundDays]);
    }

    protected function staff(Office $office): User
    {
        return User::factory()->create([
            'role' => Role::User,
            'office_id' => $office->id,
        ]);
    }

    protected function admin(Office $office): User
    {
        return User::factory()->create([
            'role' => Role::Admin,
            'office_id' => $office->id,
        ]);
    }

    protected function superAdmin(): User
    {
        return User::factory()->create([
            'role' => Role::SuperAdmin,
            'office_id' => null,
        ]);
    }

    /** Registers a document through the real action, genesis movement and all. */
    protected function registerDocument(
        Office $office,
        User $creator,
        ?DocumentType $type = null,
        DocumentPriority $priority = DocumentPriority::Normal,
    ): Document {
        return app(RegisterDocument::class)->handle(
            title: 'Request for office supplies',
            documentTypeId: ($type ?? $this->documentType())->id,
            priority: $priority,
            originatingOffice: $office,
            creator: $creator,
            description: 'A description',
            remarks: 'Initial remarks',
        );
    }
}
