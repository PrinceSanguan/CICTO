<?php

namespace App\Support;

use App\Enums\DocumentStatus;
use App\Enums\MovementAction;
use App\Exceptions\IllegalTransitionException;

/**
 * Spec §9's stage machine, as one const map.
 *
 * The same map guards the server and generates the React button set, so a
 * button that cannot be pressed is never rendered and an action that is not
 * offered cannot be forced. Controllers never branch on status: they call the
 * action and let it throw.
 */
final class DocumentWorkflow
{
    /**
     * [current status][action] => resulting status
     *
     * `forwarded` and `received` from under_review resolve back to under_review
     * on purpose: moving a folder between offices, and acknowledging that it
     * arrived, are not stage changes.
     *
     * NO APPROVAL STEP, and that is the client's decision of 2026-09-03: "dapat
     * yung mga offices wala ng approval, only received na lang, para tuloy-tuloy
     * yung naka-pila na mag-rereceive ng document". A route was stalling at the
     * third office every time, because the only action that advanced it was
     * `approved` -- which DocumentPolicy restricts to Admins and, by default,
     * refuses to the document's own author. An office with no admin, or an
     * office whose admin filed the document, could not release the folder at
     * all, and the remaining stops sat on "Waiting" forever.
     *
     * `received` is now what advances the route (see AdvanceRoute), and it is
     * ungated on purpose: acknowledging a folder that is physically on your desk
     * is a receipt, not a judgement, so MovementAction::isDecision() leaves it
     * out and the Admin-only and self-approval rules in DocumentPolicy::act()
     * never apply to it.
     *
     * WHAT THIS REMOVED. `approved` and `returned` are gone from under_review,
     * which is the only status a travelling document is ever in, so neither can
     * be performed any more -- the client asked for "received lang". The enum
     * cases stay: document_movements rows written before today still carry
     * them, §13's timeline still has to render them, and §19's reports still
     * count them.
     *
     * The 'approved' and 'returned' rows below are kept for the same reason --
     * a document that was already sitting in one of those stages when this
     * shipped still has to have a way out. Nothing can enter them any more.
     *
     * `completed` moved onto under_review because it used to hang off
     * `approved`: with approval gone it would have become unreachable, and a
     * document that can never complete can never be archived either (§16).
     *
     * REJECT IS BACK, and that is the client's decision of 2026-09-13: "napag
     * usapan na rin po natin yung reject button before natin gawin yung system,
     * and part po siya ng process na gusto naming magkaroon sa system". It was
     * §9 scope from the start -- see phase-2-workflow-and-trail.md §2, "Reject
     * -> terminal" -- and it was collateral damage of the 2026-09-03 removal
     * rather than something the client asked to lose.
     *
     * Putting it back does NOT bring back the stall that removal fixed, and the
     * reason is precise: the route advances on `received`, never on a decision
     * (see AdvanceRoute). Refusing a document is therefore OPTIONAL at every
     * stop -- an office with no Admin, or an Admin holding their own document
     * with self-approval off, simply does not get the button and receives the
     * folder as before. Nothing waits on a rejection that never comes.
     *
     * Only `under_review` offers it, exactly as the §9 stage table always had
     * it: a document still sitting in `initiated` has not been picked up by
     * anybody, so there is no office in a position to refuse it yet.
     *
     * @var array<string, array<string, string>>
     */
    public const TRANSITIONS = [
        'initiated' => [
            'forwarded' => 'under_review',
            'received' => 'under_review',
        ],
        'under_review' => [
            // Key order is button order on the document page, so the one
            // irreversible action on the panel sits last rather than between
            // two routine ones.
            'forwarded' => 'under_review',
            'received' => 'under_review',
            'completed' => 'completed',
            'rejected' => 'rejected',
        ],
        // Legacy stages. Unreachable from today; kept so documents already in
        // them at deploy time are not stranded.
        'approved' => [
            'forwarded' => 'under_review',
            'completed' => 'completed',
        ],
        'returned' => [
            'forwarded' => 'under_review',
            'received' => 'under_review',
        ],
        'rejected' => [],
        'completed' => [],
    ];

    public static function next(DocumentStatus $from, MovementAction $action): DocumentStatus
    {
        $to = self::TRANSITIONS[$from->value][$action->value] ?? null;

        if ($to === null) {
            throw new IllegalTransitionException($from, $action);
        }

        return DocumentStatus::from($to);
    }

    public static function allows(DocumentStatus $from, MovementAction $action): bool
    {
        return isset(self::TRANSITIONS[$from->value][$action->value]);
    }

    /**
     * The actions available from a given status. Drives the React button set.
     *
     * @return array<int, MovementAction>
     */
    public static function allowed(DocumentStatus $from): array
    {
        return array_map(
            static fn (string $action) => MovementAction::from($action),
            array_keys(self::TRANSITIONS[$from->value]),
        );
    }

    /**
     * §9: "Once a document is approved, a Send to Another Office button becomes
     * available." Forwarding is legal from several stages, but this is the one
     * the spec calls out, so it gets a name.
     */
    public static function canForward(DocumentStatus $from): bool
    {
        return self::allows($from, MovementAction::Forwarded);
    }
}
