<?php

namespace Database\Seeders;

use App\Enums\OfficeType;
use App\Models\Office;
use Illuminate\Database\Seeder;

/**
 * Reference data. Runs in production.
 *
 * THE CLIENT'S REAL LIST -- supplied 2026-08-18 in DTS-Questions.docx, which
 * answers the office half of client question A4. The `code` column is the alias
 * column from that document verbatim, because those aliases become
 * control-number prefixes (OCM-2026-00042) and get printed onto QR labels.
 * Changing a code later does not rewrite the numbers already issued.
 *
 * Two deliberate departures from the supplied file, both flagged back to the
 * client in docs/implementation/client-questions.md:
 *
 * 1. Names are capitalised as prose ("Office of the City Mayor"), not in the
 *    machine title case the export used ("Office Of The City Mayor"). These
 *    strings appear in every dropdown, on the public scan page and on printed
 *    labels; the code, not the name, is the join key, so nothing depends on
 *    matching the export byte for byte.
 * 2. The supplied alias for the City Economic Enterprise Affairs Office was
 *    "CEEAO / BIPU". A control number reading "CEEAO / BIPU-2026-00001" carries
 *    spaces and a slash onto paper and into URLs, so the code is CEEAO and the
 *    full "/ Baliwag Investment Promotion Unit" survives in the name.
 *
 * The supplied file also lists the City Social Welfare and Development Officer
 * twice, under CSWDO and CSWD. Both rows are created so neither alias can be
 * re-used for a different office, but CSWD ships INACTIVE -- two identical
 * entries in a "send to" dropdown is a bug report waiting to happen. If the
 * client confirms both are genuinely in use, flip is_active and give them
 * distinguishable names.
 *
 * ONE CONSTRAINT THIS FILE CANNOT ENFORCE ITSELF, so it is written down here.
 * documents.control_number is varchar(40) and the format is
 * {CODE}-{YYYY}-{NNNNN}, which spends 11 characters on the year, the sequence
 * and the two separators. offices.code allows 32, so a code of 30 characters
 * would seed cleanly and then fail on the first document registered from that
 * office -- a long way from the edit that caused it. Keep every code at 29
 * characters or fewer. There is no office admin screen and no form request, so
 * nothing checks this at runtime;
 * ReferenceDataTest::test_every_office_code_fits_a_control_number is the gate.
 * A guard in run() would be unreachable code over a literal array, and PHPStan
 * says so.
 */
class OfficeSeeder extends Seeder
{
    /**
     * The 10 placeholder codes this list retires.
     *
     * updateOrCreate never deletes, so re-seeding an existing database would
     * otherwise leave the invented offices alive and selectable beside the real
     * ones. They are deactivated rather than deleted: offices are the target of
     * a foreign key from documents and movements, and control numbers already
     * issued under them must keep resolving.
     *
     * HRMO is absent on purpose -- it is a real office and appears in the list
     * below, so it is updated in place and keeps its id.
     *
     * @var list<string>
     */
    private const RETIRED_PLACEHOLDER_CODES = [
        'MO', 'SB', 'MTO', 'MACC', 'MBO', 'MPDO', 'MEO', 'MASSO', 'MCR', 'MITO',
    ];

    public function run(): void
    {
        $offices = [
            ['code' => 'HRMO', 'name' => 'Office of the City Human Resource Management Officer'],
            ['code' => 'PDC', 'name' => 'Office of the City Planning and Development Coordinator'],
            ['code' => 'CR', 'name' => 'Office of the City Civil Registrar'],
            ['code' => 'COA', 'name' => 'Commission on Audit'],
            ['code' => 'PIO', 'name' => 'Office of the City Mayor - Public Information Office'],
            ['code' => 'ASSO', 'name' => 'Office of the City Assessor'],
            ['code' => 'ACC', 'name' => 'Office of the City Accountant'],
            ['code' => 'CLO', 'name' => 'Office of the City Legal Officer'],
            ['code' => 'OCM', 'name' => 'Office of the City Mayor'],
            ['code' => 'ARO', 'name' => 'Office of the City Mayor - City Archive and Records Office'],
            ['code' => 'CICTO', 'name' => 'Office of the City Mayor - City Information and Communications Technology Office'],
            ['code' => 'CA', 'name' => 'Office of the City Administrator'],
            ['code' => 'POPCOM', 'name' => 'Office of the City Mayor - POPCOM'],
            ['code' => 'CBO', 'name' => 'Office of the City Budget Officer'],
            ['code' => 'DILG', 'name' => 'Department of the Interior and Local Government'],
            ['code' => 'CHO', 'name' => 'Office of the City Health Officer'],
            ['code' => 'ENGR', 'name' => 'Office of the City Engineer'],
            ['code' => 'PESO', 'name' => 'Office of the City Public Employment Service Manager'],
            ['code' => 'CSWDO', 'name' => 'Office of the City Social Welfare and Development Officer'],
            ['code' => 'CAO', 'name' => 'Office of the City Mayor - Community Affairs Office'],
            ['code' => 'PACC', 'name' => 'Office of the City Mayor - Public Assistance and Complaint Center'],
            ['code' => 'LSSU', 'name' => 'Office of the City Mayor - LSSU'],
            ['code' => 'BPLO', 'name' => 'Business Permit and Licensing Office'],
            ['code' => 'CEEAO', 'name' => 'City Economic Enterprise Affairs Office / Baliwag Investment Promotion Unit'],
            ['code' => 'SDO', 'name' => 'Office of the City Mayor - Sports Development Office'],
            ['code' => 'BFP', 'name' => 'Bureau of Fire Protection'],
            ['code' => 'AO', 'name' => 'City Agriculture Office'],
            ['code' => 'ACTO', 'name' => 'City Arts, Culture, and Tourism Office'],
            ['code' => 'DRRMO', 'name' => 'City Disaster Risk Reduction and Management Office'],
            ['code' => 'TREA', 'name' => 'Office of the City Treasurer'],
            ['code' => 'PNP', 'name' => 'Philippine National Police'],
            ['code' => 'COMELEC', 'name' => 'Commission on Elections'],
            ['code' => 'BAC', 'name' => 'Bids and Awards Committee'],
            ['code' => 'OCM-LYDO', 'name' => 'Office of the City Mayor - Local Youth Development Office'],
            ['code' => 'NO', 'name' => 'City Nutrition Office'],
            ['code' => 'BCL', 'name' => 'Office of the City Mayor - Baliwag City Library'],
            ['code' => 'GSO', 'name' => 'Office of the City Mayor - General Services Office'],
            ['code' => 'CSWD', 'name' => 'Office of the City Social Welfare and Development Officer', 'is_active' => false],
            ['code' => 'GSO-MS', 'name' => 'General Services Office - Motorpool Services'],
            ['code' => 'EEAO-CS', 'name' => 'Office of the City Enterprise and Economic Affairs Officer - Cemetery Services'],
            ['code' => 'CPOSO', 'name' => 'Baliwag City Public Order and Safety Office'],
            ['code' => 'BWTV', 'name' => 'Baliwag Web TV'],
            ['code' => 'BTMO', 'name' => 'Baliwag City Traffic Management Office'],
            ['code' => 'OCM-TF', 'name' => 'Office of the City Mayor - Tricycle Franchising'],
            ['code' => 'BCEMC', 'name' => 'Office of the City Mayor - Baliwag City Employees Multipurpose Cooperative'],
            ['code' => 'CENRO-SS', 'name' => 'Office of the City Environmental and Natural Resources Officer - Sanitation Services'],
            ['code' => 'SP', 'name' => 'Office of the Sangguniang Panlungsod'],
            ['code' => 'BJMP', 'name' => 'Bureau of Jail Management and Penology'],
            ['code' => 'EEAO-PM', 'name' => 'Office of the City Enterprise and Economic Affairs Officer - Public Market'],
            ['code' => 'PO', 'name' => "Prosecutor's Office"],
            ['code' => 'OCM-SH', 'name' => 'Office of the City Mayor - Slaughter House'],
            ['code' => 'MTC', 'name' => 'Municipal Trial Court'],
            ['code' => 'CENRO', 'name' => 'Office of the City Environmental and Natural Resources Officer'],
        ];

        foreach ($offices as $index => $office) {
            Office::query()->updateOrCreate(
                ['code' => $office['code']],
                [
                    'name' => $office['name'],
                    'type' => OfficeType::Office,
                    'is_active' => $office['is_active'] ?? true,
                    /*
                     * Array position, so every dropdown lists the offices in
                     * the order the client's own file does. That order is NOT
                     * alphabetical -- it is whatever their records office
                     * exports -- and matching it is the point: a clerk
                     * scanning a 52-entry "send to" list and a clerk reading
                     * the paper sheet see the same sequence. Do not "tidy" this
                     * into alphabetical order without asking them first;
                     * Office::ordered() falls back to name, so ties already
                     * sort sensibly.
                     */
                    'sort_order' => ($index + 1) * 10,
                ],
            );
        }

        Office::query()
            ->whereIn('code', self::RETIRED_PLACEHOLDER_CODES)
            ->update(['is_active' => false]);
    }
}
