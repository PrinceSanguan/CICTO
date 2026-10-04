<?php

namespace App\Support;

use App\Enums\RouteStepKind;
use App\Models\DocumentType;
use App\Models\DocumentTypeRouteStep;
use App\Models\Office;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The suggested route for each document type (client request, 2026-09-25):
 * "pinili ko yung 'administrative order' dapat po lalabas yung suggestions na
 * kung saan sya dapat dumaan, para po maging automated yung pag pili ng
 * office". The Submit form fills the route in from here by default; choosing
 * offices by hand is still there, as the other mode.
 *
 * THE DATABASE IS THE SOURCE OF TRUTH (2026-10-04). Every type's route is
 * rows in document_type_route_steps, and a Super Admin edits them on the
 * Document Types page -- the 43 built-in types as well as their own.
 * forClient() reads those rows and nothing else.
 *
 * WHAT IS LEFT IN THIS FILE IS THE ORIGINALS: the route each built-in type
 * starts with, from the client's DTS_Office_Routing_Paths.pdf ("Suggested
 * Office Routing Paths by Document Type -- Baliwag City LGU"), mapped onto
 * the office codes in OfficeSeeder and the type codes in DocumentTypeSeeder.
 * installOriginal() copies one into the database. DocumentTypeSeeder does
 * that on every deploy for each type whose route a Super Admin has not
 * changed (`route_customized_at` is null), so an edit to a definition below
 * still reaches those types with the next deploy -- and never overwrites one
 * somebody changed on purpose. "Restore original route" on the page puts a
 * changed one back.
 *
 * WHAT A TEMPLATE IS NOT. It is a suggestion the form starts from, never a rule
 * the server enforces: StoreDocumentRequest still validates the offices that
 * were actually posted, whichever mode produced them, and a clerk may edit the
 * suggestion before submitting it. A route that went wrong is fixed by the
 * person holding the folder, as it always was.
 *
 * Every step is one of (App\Enums\RouteStepKind):
 *  - an office, by code -- skipped with a warning when that office is not
 *    active on this installation;
 *  - ORIGIN, the office filing it ("BPLO -- release" when BPLO filed it);
 *  - CHOOSE, an office the sender picks ("Concerned Office -- receives"),
 *    optionally from a short list and with a suggestion;
 *  - SAME, the office an earlier CHOOSE step picked (Permit's "concerned
 *    regulating office -- release");
 *  - a NOTE, for a step the system does not perform: a broadcast, a release to
 *    a member of the public, a reply sent out.
 * Any office step can be OPTIONAL -- the PDF's "if ordinance-based", "if with
 * allowance", "if needed" -- and is left out until the sender ticks it. An
 * optional step can also start TICKED: the BAC's committee members on a
 * voucher are all on it unless the sender takes one off.
 *
 * The office filing the document is always stop 1, whatever the template
 * says: StoreDocumentRequest requires it, because it registers the document.
 * So a template's first office is where the folder goes FROM the filer, and
 * when the filer is that office already (BPLO filing a Business Permit) the
 * two are the same stop -- see RoutePlan::collapse().
 *
 * THREE THINGS IN THE PDF THAT ARE MORE THAN A ROUTE:
 *  - "Broadcast to ALL offices" (Executive Order, Memorandum Circular). Not
 *    a stop: the folder cannot be on 52 desks. The document page has a
 *    Broadcast button for these types -- see BroadcastDocument -- and the
 *    route says where in the trip it belongs.
 *  - The BAC step of a Disbursement Voucher "with City Accountant, CPDO, LCR,
 *    CENRO, City Assessor". The committee's members are stops of their own,
 *    straight after BAC, because one folder is in one place at a time
 *    (decision D13) and each member has to have it in hand.
 *  - Confidential. Its route is here -- straight to the City Mayor or HRMO --
 *    and who may SEE it is App\Support\Confidential.
 *
 * A Super Admin's own types never had a definition here: their routes have
 * only ever been rows, and only `office` ones.
 */
final class RouteTemplates
{
    public const ORIGIN = '@origin';

    private const BROADCAST_NOTE = 'Broadcast to all offices: when it reaches this point, press Broadcast on the document page. Every office is notified and can read it, and the folder carries on.';

    /**
     * @var array<string, array{steps: list<array<string, mixed>>, note?: string}>|null
     */
    private static ?array $definitions = null;

    /**
     * The templates for the types the form offers, read from their saved
     * routes and resolved to the offices the form offers -- keyed by document
     * type id, which is what the type dropdown posts. A type with no saved
     * route is left out, and the form falls back to choosing by hand.
     *
     * @param  Collection<int, DocumentType>  $types  with `route_note`, `is_confidential` and `allows_broadcast`
     * @param  Collection<int, Office>  $offices  the active offices
     * @return array<int, array{note: string|null, confidential: bool, broadcast: bool, steps: list<array<string, mixed>>}>
     */
    public static function forClient(Collection $types, Collection $offices): array
    {
        // id => true, for the offices the form can send to.
        $active = $offices->pluck('id')->map(static fn ($id): int => (int) $id)->flip();

        // One query for every type's steps, with the office name for a step
        // whose office has since been deactivated.
        (new EloquentCollection($types->all()))->load('routeSteps.office:id,name');

        $templates = [];

        foreach ($types as $type) {
            if ($type->routeSteps->isEmpty()) {
                continue;
            }

            $templates[$type->id] = [
                'note' => $type->route_note,
                // The form keeps a Confidential route to the choice below --
                // StoreDocumentRequest refuses any other -- and says where a
                // broadcast type's Broadcast button is.
                'confidential' => $type->is_confidential,
                'broadcast' => $type->allows_broadcast,
                'steps' => array_values($type->routeSteps
                    ->map(static fn (DocumentTypeRouteStep $step): array => self::forForm($step, $active))
                    ->all()),
            ];
        }

        return $templates;
    }

    /**
     * Put a built-in type's original route back, from the definitions below.
     *
     * Writes only what differs, so the seeder can call it on every deploy
     * without touching a row that is already right. Clears
     * `route_customized_at`: the route is the system's again, and later
     * deploys keep it current.
     *
     * An office code this installation does not have at all is left out (a
     * step must point at a real office); one it has deactivated is kept, and
     * the Submit form names it as missing.
     *
     * @return bool whether anything changed
     */
    public static function installOriginal(DocumentType $type): bool
    {
        $definition = $type->is_custom ? null : (self::definitions()[$type->code] ?? null);

        if ($definition === null) {
            return false;
        }

        $rows = self::rows($definition['steps'], Office::query()->pluck('id', 'code')->map(static fn ($id): int => (int) $id));
        $note = $definition['note'] ?? null;

        $current = $type->routeSteps()->get()
            ->map(static fn (DocumentTypeRouteStep $step): array => $step->definition())
            ->all();

        $type->forceFill(['route_note' => $note, 'route_customized_at' => null]);

        if ($current === $rows && ! $type->isDirty()) {
            return false;
        }

        DB::transaction(static function () use ($type, $current, $rows): void {
            if ($current !== $rows) {
                $type->routeSteps()->delete();
                $type->routeSteps()->createMany(self::positioned($rows));
            }

            $type->save();
        });

        return true;
    }

    /**
     * Rows numbered 1, 2, 3... in order: positions ARE the order, and a
     * `same` step's target is one.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function positioned(array $rows): array
    {
        return array_map(
            static fn (array $row, int $index): array => $row + ['position' => $index + 1],
            $rows,
            array_keys($rows),
        );
    }

    /**
     * The type codes that have an original route. The tests compare it with
     * the seeder's list, so a type added there without a route cannot go
     * unnoticed.
     *
     * @return list<string>
     */
    public static function typeCodes(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * Every office code an original route names.
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        $codes = [];

        foreach (self::definitions() as $definition) {
            foreach ($definition['steps'] as $step) {
                foreach ([$step['code'] ?? null, $step['suggested'] ?? null, ...($step['only'] ?? [])] as $code) {
                    if (is_string($code) && $code !== self::ORIGIN) {
                        $codes[$code] = true;
                    }
                }
            }
        }

        return array_keys($codes);
    }

    /**
     * One saved step, as the Submit form's RouteTemplateStep.
     *
     * @param  Collection<int, int>  $active  active office id => anything
     * @return array<string, mixed>
     */
    private static function forForm(DocumentTypeRouteStep $step, Collection $active): array
    {
        $purpose = $step->purpose ?? '';

        return match ($step->kind) {
            RouteStepKind::Office => [
                'kind' => 'office',
                'office_id' => $active->has($step->office_id) ? $step->office_id : null,
                // Only read when office_id is null: the form names a live
                // office from its own list.
                'missing_office' => $active->has($step->office_id) ? null : $step->office?->name,
                'purpose' => $purpose,
                'optional' => $step->is_optional,
                'checked' => $step->is_checked,
            ],
            RouteStepKind::Choose => [
                'kind' => 'choose',
                'purpose' => $purpose,
                'optional' => $step->is_optional,
                'suggested' => match (true) {
                    $step->suggests_origin => 'origin',
                    $step->suggested_office_id !== null && $active->has($step->suggested_office_id) => $step->suggested_office_id,
                    default => null,
                },
                // Null: any office. A list the installation has none of left
                // is still a list, so the step reads as unanswerable rather
                // than silently widening to every office.
                'only' => $step->only_office_ids === null
                    ? null
                    : array_values(array_filter(
                        array_map('intval', $step->only_office_ids),
                        static fn (int $id): bool => $active->has($id),
                    )),
            ],
            // 0-based, as the form counts.
            RouteStepKind::Same => ['kind' => 'same', 'step' => (int) $step->same_as_position - 1, 'purpose' => $purpose],
            RouteStepKind::Note => ['kind' => 'note', 'purpose' => $purpose],
        };
    }

    /**
     * An original route as rows, in DocumentTypeRouteStep::definition()'s
     * shape.
     *
     * @param  list<array<string, mixed>>  $steps
     * @param  Collection<string, int>  $ids  every office, active or not, by code
     * @return list<array{kind: string, office_id: int|null, is_optional: bool, is_checked: bool, suggested_office_id: int|null, suggests_origin: bool, only_office_ids: list<int>|null, same_as_position: int|null, purpose: string|null}>
     */
    private static function rows(array $steps, Collection $ids): array
    {
        $rows = [];

        // Original index => saved position, for SAME steps: a step left out
        // above one moves it up.
        $positions = [];

        foreach ($steps as $index => $step) {
            $row = [
                'kind' => $step['kind'],
                'office_id' => null,
                'is_optional' => (bool) ($step['optional'] ?? false),
                'is_checked' => (bool) ($step['checked'] ?? false),
                'suggested_office_id' => null,
                'suggests_origin' => false,
                'only_office_ids' => null,
                'same_as_position' => null,
                'purpose' => ($step['purpose'] ?? '') === '' ? null : $step['purpose'],
            ];

            switch ($step['kind']) {
                case 'office':
                    $row['office_id'] = $ids->get($step['code']);

                    if ($row['office_id'] === null) {
                        continue 2;
                    }

                    break;

                case 'choose':
                    $row['suggests_origin'] = $step['suggested'] === self::ORIGIN;
                    $row['suggested_office_id'] = $step['suggested'] === null || $row['suggests_origin']
                        ? null
                        : $ids->get($step['suggested']);
                    $row['only_office_ids'] = $step['only'] === null
                        ? null
                        : array_values(array_filter(array_map(
                            static fn (string $code): ?int => $ids->get($code),
                            $step['only'],
                        )));

                    break;

                case 'same':
                    $row['same_as_position'] = $positions[$step['step']] ?? null;

                    if ($row['same_as_position'] === null) {
                        continue 2;
                    }

                    break;
            }

            $rows[] = $row;
            $positions[$index] = count($rows);
        }

        return $rows;
    }

    /**
     * @return array<string, array{steps: list<array<string, mixed>>, note?: string}>
     */
    private static function definitions(): array
    {
        return self::$definitions ??= [
            // ── Permits, Business & Franchising ──────────────────────────
            'BUSINESS-PERMIT' => ['steps' => [
                self::at('BPLO', 'Receives the application'),
                self::at('PDC', 'Zoning clearance'),
                self::at('BFP', 'Fire safety inspection'),
                self::at('ENGR', 'Building and structural inspection'),
                self::at('TREA', 'Assessment and payment'),
                self::at('OCM', 'Signature and approval'),
                self::at('BPLO', 'Release'),
            ]],
            'MAYORS-PERMIT' => ['steps' => [
                self::at('BPLO', 'Receives the application'),
                self::at('PDC', 'Zoning clearance'),
                self::at('ENGR', 'Inspection'),
                self::at('TREA', 'Payment'),
                self::at('OCM', 'Approval'),
                self::at('BPLO', 'Release'),
            ]],
            'CERT-CLOSURE' => ['steps' => [
                self::at('BPLO', 'Receives the request'),
                self::at('TREA', 'Clears outstanding fees'),
                self::at('OCM', 'Approval'),
                self::at('BPLO', 'Release'),
            ]],
            'CERT-NO-BUSINESS' => ['steps' => [
                self::at('BPLO', 'Verifies the records'),
                self::at('ARO', 'Release'),
            ]],
            'CLOSURE-ORDER' => ['steps' => [
                self::at('BPLO', 'Initiates the order'),
                self::at('CLO', 'Legal review'),
                self::at('OCM', 'Approval and signature'),
                self::at('ARO', 'Filing and release'),
            ]],
            'CONSTRUCTION-PERMIT' => ['steps' => [
                self::at('ENGR', 'Receives the application'),
                self::at('PDC', 'Zoning clearance'),
                self::at('BFP', 'Fire safety clearance'),
                self::at('TREA', 'Payment'),
                self::at('OCM', 'Approval'),
                self::at('ENGR', 'Release'),
            ]],
            'PERMIT' => ['steps' => [
                self::choose('Concerned regulating office — receives and evaluates', suggested: self::ORIGIN),
                self::at('OCM', 'Approval'),
                self::same(0, 'Concerned regulating office — release'),
            ]],
            'FRANCHISE' => ['steps' => [
                self::at('BPLO', 'Receives the application'),
                self::at('BTMO', 'Route and operations review'),
                self::at('SP', 'Approval or ordinance'),
                self::at('OCM', 'Signature'),
                self::at('BPLO', 'Release'),
            ]],
            'FRANCHISE-TRICYCLE' => ['steps' => [
                self::at('OCM-TF', 'Receives the application'),
                self::at('BTMO', 'Route and operations review'),
                self::at('SP', 'Approval'),
                self::at('OCM', 'Signature'),
                self::at('OCM-TF', 'Release'),
            ]],

            // ── Legal, Clearances & Orders ───────────────────────────────
            'ADMIN-ORDER' => ['steps' => [
                self::at('OCM', 'Issues the order'),
                self::at('CLO', 'Legal review'),
                self::at('ARO', 'Filing and release'),
            ]],
            'EXEC-ORDER' => ['steps' => [
                self::at('OCM', 'Issues the order'),
                self::at('CLO', 'Legal review'),
                self::at('SP', 'For record'),
                self::note(self::BROADCAST_NOTE),
                self::at('ARO', 'Final filing'),
            ]],
            'DEMOLITION-ORDER' => ['steps' => [
                self::at('CLO', 'Legal basis review'),
                self::at('ENGR', 'Structural assessment'),
                self::at('OCM', 'Approval'),
                self::at('SP', 'Only if ordinance-based', optional: true),
                self::at('ARO', 'Filing and release'),
            ]],
            'MAYORS-CLEARANCE' => ['steps' => [
                self::at('PNP', 'Background check'),
                self::at('PACC', 'Verification'),
                self::at('OCM', 'Approval and signature'),
                self::note('Released to the requesting party.'),
            ]],
            'AFFIDAVIT-NF' => ['steps' => self::affidavit()],
            'AFFIDAVIT-ITR' => ['steps' => self::affidavit()],
            'RESOLUTION' => ['steps' => [
                self::at('SP', 'Deliberates and passes'),
                self::at('OCM', 'Approves or vetoes'),
                self::at('SP', 'SP Secretariat — records'),
                self::at('ARO', 'Filing'),
            ]],
            'CONFIDENTIAL' => [
                'note' => 'Confidential: it goes straight to the City Mayor or HRMO as soon as it is filed, and only you and the people of the office it goes to can see it.',
                'steps' => [
                    self::choose('Receives it — City Mayor or HRMO only', suggested: 'OCM', only: ['OCM', 'HRMO']),
                ],
            ],

            // ── Financial ────────────────────────────────────────────────
            // The client's own list (2026-09-25) names offices only, so most
            // steps carry no purpose rather than one made up here.
            'DV' => ['steps' => [
                self::at('CA'),
                self::at('CBO'),
                self::at('TREA'),
                self::at('BAC', 'Then to each committee member below'),
                // "BAC -- with City Accountant, CPDO, City Civil Registrar
                // (LCR), CENRO, City Assessor", in the PDF's order. Ticked,
                // so a member can be taken off for a voucher that skips them.
                self::member('ACC'),
                self::member('PDC'),
                self::member('CR'),
                self::member('CENRO'),
                self::member('ASSO'),
                self::at('OCM'),
                self::at('GSO'),
                self::at('ACC'),
                self::at('TREA', 'Final release'),
            ]],
            'PO' => ['steps' => [
                self::at('BAC', 'Procurement process'),
                self::at('CBO', 'Fund availability'),
                self::at('ACC', 'Obligation'),
                self::at('OCM', 'Approval'),
                self::at('GSO', 'Release'),
            ]],
            'PAYROLL' => ['steps' => [
                self::at('HRMO', 'Prepares the payroll'),
                self::at('CBO', 'Fund certification'),
                self::at('ACC', 'Processing'),
                self::at('TREA', 'Release'),
            ]],

            // ── HR & Personnel ───────────────────────────────────────────
            'MEMO-HR' => ['steps' => [
                self::at('HRMO', 'Issues the memo'),
                self::choose('Concerned employee\'s office — receives'),
            ]],
            'MEMO-PSB' => ['steps' => [
                self::at('HRMO', 'Prepares it'),
                self::at('OCM', 'Approval'),
                self::choose('Concerned office — receives'),
            ]],
            'NOTICE-VACANCY' => ['steps' => [
                self::at('HRMO', 'Prepares the notice'),
                self::at('OCM', 'Approval'),
                self::at('ARO', 'Posting'),
            ]],
            'OATH-OF-OFFICE' => ['steps' => [
                self::at('HRMO', 'Prepares it'),
                self::at('OCM', 'Administers the oath'),
                self::at('ARO', 'Filing'),
            ]],
            'TO' => [
                'note' => 'Filed by the requesting employee and endorsed by their department head before it leaves the office.',
                'steps' => [
                    self::at('HRMO', 'Review'),
                    self::at('OCM', 'Approval'),
                    self::at('ACC', 'Only if with allowance', optional: true),
                ],
            ],
            'CERT-UNEMPLOYED' => ['steps' => [
                self::at('PESO', 'Verifies'),
                self::at('OCM', 'Approval'),
                self::at('ARO', 'Release'),
            ]],

            // ── Executive/Internal Memos & Correspondence ────────────────
            'MEMO' => ['steps' => [
                self::choose('Concerned office — receives'),
            ]],
            'MEMO-CIRCULAR' => ['steps' => [
                self::at('OCM', 'Issues it'),
                self::note(self::BROADCAST_NOTE),
            ]],
            'MEMO-MA' => ['steps' => [
                self::at('CA', 'Issues it'),
                self::choose('Concerned office — receives'),
            ]],
            'MEMO-ORDER-OCM' => ['steps' => [
                self::at('OCM', 'Issues it'),
                self::choose('Concerned office — receives'),
            ]],
            'MEMO-TMO' => ['steps' => [
                self::at('BTMO', 'Issues it'),
                self::choose('Concerned office — receives'),
            ]],
            'LETTER-EXTERNAL' => ['steps' => [
                self::at('ARO', 'Receiving and logging'),
                self::choose('City Mayor or the concerned office — action', suggested: 'OCM'),
                self::note('Reply sent out.'),
            ]],
            'LETTER-INTERNAL' => ['steps' => [
                self::choose('Receiving office — receives it directly'),
            ]],
            'GENERAL-INCOMING' => ['steps' => [
                self::at('ARO', 'Receiving and logging'),
                self::choose('Concerned office'),
            ]],
            'ENDORSEMENT' => ['steps' => [
                self::choose('Receiving or concerned office'),
            ]],
            'REFERRAL' => ['steps' => [
                self::choose('Receiving or concerned office'),
            ]],
            'REQUEST' => ['steps' => [
                self::choose('Concerned office — evaluates'),
                self::choose('Approving authority', suggested: 'OCM', only: ['OCM', 'CA']),
            ]],
            'PROPOSAL' => ['steps' => [
                self::at('CA', 'Review'),
                self::at('OCM', 'Approval'),
            ]],
            'CERTIFICATION' => ['steps' => self::certification()],
            'CERT-DOCS-NEEDED' => ['steps' => self::certification()],

            // ── Meetings ─────────────────────────────────────────────────
            'NOTICE-MEETING' => ['steps' => [
                self::choose('Attendees\' office — receives'),
            ]],
            'MINUTES' => ['steps' => [
                self::choose('Attendees or concerned office — receives'),
            ]],
            'NOTICE' => ['steps' => [
                self::choose('Concerned office — receives'),
            ]],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function affidavit(): array
    {
        return [
            self::at('TREA', 'Verifies the records'),
            self::at('CLO', 'Notarization and review'),
            self::at('ARO', 'Filing and release'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function certification(): array
    {
        return [
            self::choose('Concerned office — verifies', suggested: self::ORIGIN),
            self::at('ARO', 'Prepares the document'),
            self::at('OCM', 'Only if needed', optional: true),
            self::note('Released.'),
        ];
    }

    /** @return array<string, mixed> */
    private static function at(string $code, string $purpose = '', bool $optional = false): array
    {
        return ['kind' => 'office', 'code' => $code, 'purpose' => $purpose, 'optional' => $optional];
    }

    /**
     * A BAC committee member on a voucher: on the route unless unticked.
     *
     * @return array<string, mixed>
     */
    private static function member(string $code): array
    {
        return ['kind' => 'office', 'code' => $code, 'purpose' => 'BAC committee member', 'optional' => true, 'checked' => true];
    }

    /**
     * @param  string|null  $suggested  an office code, or ORIGIN
     * @param  list<string>|null  $only  office codes; null for any office
     * @return array<string, mixed>
     */
    private static function choose(string $purpose, ?string $suggested = null, ?array $only = null, bool $optional = false): array
    {
        return ['kind' => 'choose', 'purpose' => $purpose, 'suggested' => $suggested, 'only' => $only, 'optional' => $optional];
    }

    /**
     * The office an earlier step chose.
     *
     * @return array<string, mixed>
     */
    private static function same(int $step, string $purpose): array
    {
        return ['kind' => 'same', 'step' => $step, 'purpose' => $purpose];
    }

    /** @return array<string, mixed> */
    private static function note(string $purpose): array
    {
        return ['kind' => 'note', 'purpose' => $purpose];
    }
}
