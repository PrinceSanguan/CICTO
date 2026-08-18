<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use Illuminate\Database\Seeder;

/**
 * Reference data. Runs in production.
 *
 * THE CLIENT'S REAL LIST -- supplied 2026-08-18 in DTS-Questions.docx, which
 * answers the document-type half of client question A4. The names are the
 * client's, in the client's order (alphabetical).
 *
 * turnaround_days IS DELIBERATELY NULL ON EVERY ROW. The client's file answers
 * "how many days should each type take?" with "ARO" -- the City Archive and
 * Records Office, a different office, who had not been asked yet on 2026-08-18.
 * Inventing numbers is what the previous placeholder list did and it is what
 * makes §11 report a fiction, so nothing is invented here.
 *
 * What that means in practice: Deadlines::dueAt falls back to
 * cicto.deadlines.default_turnaround_days for every type, so every document
 * gets the same provisional SLA (3 calendar days) rather than a per-type one.
 * That number is env-tunable via CICTO_DEFAULT_TURNAROUND_DAYS so the LGU can
 * move it without a deployment while ARO's answer is outstanding. There is no
 * admin screen for document types, so the real per-type numbers will be a code
 * change and a deploy -- see client-questions.md A4.
 *
 * The `code` column is not used in control numbers (only the office code is)
 * and nothing in the application looks a type up by code, so these codes are
 * internal keys. They are derived from the client's names because the client's
 * file supplies no codes.
 *
 * HOW 44 SUPPLIED LINES BECAME 43 ROWS. Exactly one line was dropped:
 * "Mayor's Clearance" is listed twice in the client's file, so it is seeded
 * once. Nothing else was merged.
 *
 * Separately, and NOT a merge: the supplied "Affidavit of Non- Filing of Income
 * Tax Return" carries a stray space after the hyphen, corrected here. It is a
 * DIFFERENT document from "Affidavit of Non-Filing" two lines below it in the
 * client's list -- one is about an income tax return and one is not -- so both
 * are seeded, as AFFIDAVIT-ITR and AFFIDAVIT-NF. Do not "finish" a
 * deduplication here; there is none left to do.
 */
class DocumentTypeSeeder extends Seeder
{
    /**
     * The placeholder codes this list retires outright.
     *
     * Same reasoning as OfficeSeeder::RETIRED_PLACEHOLDER_CODES: updateOrCreate
     * never deletes, so without this a re-seed leaves the invented types in the
     * Submit form beside the real ones. Deactivated, not deleted --
     * documents.document_type_id is a foreign key.
     *
     * MEMO, PERMIT, DV, PO and TO are absent: each of those placeholder codes
     * names the same thing as one of the client's real types, so they are
     * re-used below and updated in place rather than retired. That matters for
     * more than tidiness -- a retired "Memorandum" sitting beside an active
     * "Memorandum" gives the type filter two entries with one label, where the
     * one on offer silently excludes every memorandum filed before the upgrade.
     * Re-using the code keeps it one row, and the documents already pointing at
     * it come along.
     *
     * The four that stay retired have no 1:1 successor: LETTER
     * ("Letter / Correspondence") split into External and Internal, ORD
     * ("Ordinance / Resolution") bundled two things the client lists as one,
     * CLR ("Clearance") is narrower here as "Mayor's Clearance", and PR
     * ("Purchase Request") is not in the client's list at all.
     *
     * @var list<string>
     */
    private const RETIRED_PLACEHOLDER_CODES = [
        'LETTER', 'PR', 'ORD', 'CLR',
    ];

    public function run(): void
    {
        $types = [
            ['code' => 'ADMIN-ORDER', 'name' => 'Administrative Order'],
            ['code' => 'AFFIDAVIT-ITR', 'name' => 'Affidavit of Non-Filing of Income Tax Return'],
            ['code' => 'AFFIDAVIT-NF', 'name' => 'Affidavit of Non-Filing'],
            ['code' => 'BUSINESS-PERMIT', 'name' => 'Business Permit'],
            ['code' => 'CERT-CLOSURE', 'name' => 'Certificate of Closure of Business'],
            ['code' => 'CERT-NO-BUSINESS', 'name' => 'Certificate of No Business'],
            ['code' => 'CERT-UNEMPLOYED', 'name' => 'Certificate of Unemployment'],
            ['code' => 'CERTIFICATION', 'name' => 'Certification'],
            ['code' => 'CERT-DOCS-NEEDED', 'name' => 'Certification of Documents Needed'],
            ['code' => 'CLOSURE-ORDER', 'name' => 'Closure Order'],
            ['code' => 'CONFIDENTIAL', 'name' => 'Confidential'],
            ['code' => 'CONSTRUCTION-PERMIT', 'name' => 'Construction Permit'],
            ['code' => 'DEMOLITION-ORDER', 'name' => 'Demolition Order'],
            ['code' => 'DV', 'name' => 'Disbursement Voucher'],
            ['code' => 'ENDORSEMENT', 'name' => 'Endorsement'],
            ['code' => 'EXEC-ORDER', 'name' => 'Executive Order'],
            ['code' => 'FRANCHISE', 'name' => 'Franchise'],
            ['code' => 'FRANCHISE-TRICYCLE', 'name' => 'Franchise for Tricycle'],
            ['code' => 'GENERAL-INCOMING', 'name' => 'General (Incoming)'],
            ['code' => 'LETTER-EXTERNAL', 'name' => 'Letter (External)'],
            ['code' => 'LETTER-INTERNAL', 'name' => 'Letter (Internal)'],
            ['code' => 'MAYORS-CLEARANCE', 'name' => "Mayor's Clearance"],
            ['code' => 'MAYORS-PERMIT', 'name' => "Mayor's Permit"],
            ['code' => 'MEMO', 'name' => 'Memorandum'],
            ['code' => 'MEMO-CIRCULAR', 'name' => 'Memorandum Circular'],
            ['code' => 'MEMO-HR', 'name' => 'Memorandum HR'],
            ['code' => 'MEMO-MA', 'name' => 'Memorandum MA'],
            ['code' => 'MEMO-ORDER-OCM', 'name' => "Memorandum Order from Mayor's Office"],
            ['code' => 'MEMO-PSB', 'name' => 'Memorandum PSB'],
            ['code' => 'MEMO-TMO', 'name' => 'Memorandum TMO'],
            ['code' => 'MINUTES', 'name' => 'Minutes of Meeting'],
            ['code' => 'NOTICE', 'name' => 'Notice'],
            ['code' => 'NOTICE-MEETING', 'name' => 'Notice of Meeting'],
            ['code' => 'NOTICE-VACANCY', 'name' => 'Notice of Vacancy'],
            ['code' => 'OATH-OF-OFFICE', 'name' => 'Oath of Office'],
            ['code' => 'PAYROLL', 'name' => 'Payroll'],
            ['code' => 'PERMIT', 'name' => 'Permit'],
            ['code' => 'PROPOSAL', 'name' => 'Proposal'],
            ['code' => 'PO', 'name' => 'Purchase Order'],
            ['code' => 'REFERRAL', 'name' => 'Referral'],
            ['code' => 'REQUEST', 'name' => 'Request'],
            ['code' => 'RESOLUTION', 'name' => 'Resolution'],
            ['code' => 'TO', 'name' => 'Travel Order'],
        ];

        foreach ($types as $index => $type) {
            DocumentType::query()->updateOrCreate(
                ['code' => $type['code']],
                [
                    'name' => $type['name'],
                    // See the class docblock: not a guess, an absence.
                    'turnaround_days' => null,
                    'requires_approval' => true,
                    'is_active' => true,
                    'sort_order' => ($index + 1) * 10,
                ],
            );
        }

        DocumentType::query()
            ->whereIn('code', self::RETIRED_PLACEHOLDER_CODES)
            ->update(['is_active' => false]);
    }
}
