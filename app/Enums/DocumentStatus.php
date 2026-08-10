<?php

namespace App\Enums;

/**
 * The internal stage vocabulary, stored as documents.status string(32).
 *
 * Spec §8 names the four values the client filters by -- Pending, In Process,
 * Rejected, Completed -- and those are literal acceptance criteria. The internal
 * machine is richer, so publicLabel() is the single place the two vocabularies
 * meet. Do not scatter that mapping.
 */
enum DocumentStatus: string
{
    case Initiated = 'initiated';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Returned = 'returned';
    case Rejected = 'rejected';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Initiated => 'Initiated',
            self::UnderReview => 'Under Review',
            self::Approved => 'Approved',
            self::Returned => 'Returned',
            self::Rejected => 'Rejected',
            self::Completed => 'Completed',
        };
    }

    /**
     * The §8 client-facing name.
     *
     * Approved maps to "In Process", not "Completed": §9 says the "Send to
     * Another Office" button appears *after* approval, so an approved document
     * is still moving.
     */
    public function publicLabel(): string
    {
        return match ($this) {
            self::Initiated, self::Returned => 'Pending',
            self::UnderReview, self::Approved => 'In Process',
            self::Rejected => 'Rejected',
            self::Completed => 'Completed',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Rejected], true);
    }

    /** Tailwind colour token consumed by <StatusBadge>. */
    public function tone(): string
    {
        return match ($this) {
            self::Initiated => 'slate',
            self::UnderReview => 'amber',
            self::Approved => 'sky',
            self::Returned => 'orange',
            self::Rejected => 'red',
            self::Completed => 'emerald',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return array<int, string> */
    public static function terminalValues(): array
    {
        return array_values(array_map(
            static fn (self $s) => $s->value,
            array_filter(self::cases(), static fn (self $s) => $s->isTerminal()),
        ));
    }

    /**
     * Options for the §8 status filter, collapsed to the four client-facing
     * names. Each option carries the internal values it covers so the query can
     * expand it back out.
     *
     * @return array<int, array{value: string, label: string, statuses: array<int, string>}>
     */
    public static function publicOptions(): array
    {
        $grouped = [];

        foreach (self::cases() as $case) {
            $grouped[$case->publicLabel()][] = $case->value;
        }

        return array_map(
            static fn (string $label, array $statuses) => [
                'value' => str($label)->lower()->replace(' ', '_')->value(),
                'label' => $label,
                'statuses' => $statuses,
            ],
            array_keys($grouped),
            array_values($grouped),
        );
    }

    /**
     * Expand a §8 filter value back into the internal statuses it covers.
     *
     * @return array<int, string>
     */
    public static function fromPublicValue(string $value): array
    {
        foreach (self::publicOptions() as $option) {
            if ($option['value'] === $value) {
                return $option['statuses'];
            }
        }

        return [];
    }
}
