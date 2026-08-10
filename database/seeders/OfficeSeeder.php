<?php

namespace Database\Seeders;

use App\Enums\OfficeType;
use App\Models\Office;
use Illuminate\Database\Seeder;

/**
 * Reference data. Runs in production.
 *
 * PLACEHOLDER LIST -- client question A4 is unanswered. These are the offices a
 * Philippine municipality typically has, with their conventional acronyms. The
 * codes become control-number prefixes (MPDO-2026-00042) and get printed on QR
 * labels, so they must be confirmed with the client BEFORE any document is
 * registered in production. Changing a code later does not rewrite the numbers
 * already issued.
 */
class OfficeSeeder extends Seeder
{
    public function run(): void
    {
        $offices = [
            ['code' => 'MO', 'name' => "Mayor's Office", 'sort_order' => 10],
            ['code' => 'SB', 'name' => 'Sangguniang Bayan', 'sort_order' => 20],
            ['code' => 'MTO', 'name' => "Municipal Treasurer's Office", 'sort_order' => 30],
            ['code' => 'MACC', 'name' => "Municipal Accountant's Office", 'sort_order' => 40],
            ['code' => 'MBO', 'name' => 'Municipal Budget Office', 'sort_order' => 50],
            ['code' => 'MPDO', 'name' => 'Municipal Planning and Development Office', 'sort_order' => 60],
            ['code' => 'MEO', 'name' => 'Municipal Engineering Office', 'sort_order' => 70],
            ['code' => 'HRMO', 'name' => 'Human Resource Management Office', 'sort_order' => 80],
            ['code' => 'MASSO', 'name' => 'Municipal Assessor\'s Office', 'sort_order' => 90],
            ['code' => 'MCR', 'name' => 'Municipal Civil Registrar', 'sort_order' => 100],
            ['code' => 'MITO', 'name' => 'Municipal Information Technology Office', 'sort_order' => 110],
        ];

        foreach ($offices as $office) {
            Office::query()->updateOrCreate(
                ['code' => $office['code']],
                [
                    'name' => $office['name'],
                    'type' => OfficeType::Office,
                    'is_active' => true,
                    'sort_order' => $office['sort_order'],
                ],
            );
        }
    }
}
