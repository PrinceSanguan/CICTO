<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use Illuminate\Database\Seeder;

/**
 * Reference data. Runs in production.
 *
 * PLACEHOLDER LIST -- client question A4. turnaround_days is the §11 SLA and
 * drives every overdue badge in the system, so these numbers are guesses until
 * the client supplies real ones.
 */
class DocumentTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['code' => 'MEMO', 'name' => 'Memorandum', 'turnaround_days' => 3, 'sort_order' => 10],
            ['code' => 'LETTER', 'name' => 'Letter / Correspondence', 'turnaround_days' => 5, 'sort_order' => 20],
            ['code' => 'PR', 'name' => 'Purchase Request', 'turnaround_days' => 7, 'sort_order' => 30],
            ['code' => 'PO', 'name' => 'Purchase Order', 'turnaround_days' => 7, 'sort_order' => 40],
            ['code' => 'DV', 'name' => 'Disbursement Voucher', 'turnaround_days' => 10, 'sort_order' => 50],
            ['code' => 'TO', 'name' => 'Travel Order', 'turnaround_days' => 2, 'sort_order' => 60],
            ['code' => 'ORD', 'name' => 'Ordinance / Resolution', 'turnaround_days' => 15, 'sort_order' => 70],
            ['code' => 'CLR', 'name' => 'Clearance', 'turnaround_days' => 3, 'sort_order' => 80],
            ['code' => 'PERMIT', 'name' => 'Permit Application', 'turnaround_days' => 10, 'sort_order' => 90],
        ];

        foreach ($types as $type) {
            DocumentType::query()->updateOrCreate(
                ['code' => $type['code']],
                [
                    'name' => $type['name'],
                    'turnaround_days' => $type['turnaround_days'],
                    'requires_approval' => true,
                    'is_active' => true,
                    'sort_order' => $type['sort_order'],
                ],
            );
        }
    }
}
