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
     * `forwarded` from under_review resolves back to under_review on purpose:
     * routing a folder between offices is not a stage change. The
     * approved -> forwarded -> under_review path is the multi-signatory chain,
     * e.g. the Mayor's office signing after a department head.
     *
     * @var array<string, array<string, string>>
     */
    public const TRANSITIONS = [
        'initiated' => [
            'forwarded' => 'under_review',
            'received' => 'under_review',
        ],
        'under_review' => [
            'forwarded' => 'under_review',
            'approved' => 'approved',
            'rejected' => 'rejected',
            'returned' => 'returned',
        ],
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
