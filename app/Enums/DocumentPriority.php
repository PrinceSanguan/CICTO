<?php

namespace App\Enums;

/**
 * Spec §5 (form field) and §6 (one of the three classification axes).
 *
 * Priority is a sort key and a badge. It deliberately does NOT shorten due_at:
 * that would give one number two sources of truth. If the client wants priority
 * to affect the SLA it becomes an agreed config delta, not an implicit rule.
 *
 * THREE LEVELS, IN THE CLIENT'S WORDS, since 2026-09-19. They sent their paper
 * routing slip -- "High - Must be done within 24 hours. / Medium - Within the
 * week. / Low - Whenever it is possible." -- and asked for the form to say
 * exactly that, and for the field to be required.
 *
 * The STORED values did not change, and that is deliberate: `normal` is the
 * level the client now calls Medium, so every existing row, bookmarked filter
 * and report keeps meaning what it meant. Only the wording moved.
 *
 * `urgent` is legacy, kept for the same reason DocumentStatus keeps Approved:
 * documents filed before today still carry it. Nothing new can be filed as
 * urgent (see selectable()), and an old one reads -- and is coloured -- High,
 * the top of the client's three levels, so the word they no longer use is
 * never on screen. It still sorts above High: it was filed as the more
 * pressing of the two, and nothing about today's change says otherwise.
 *
 * The "within 24 hours" wording is a promise about urgency, NOT a deadline.
 * due_at still comes from the document type alone -- see the paragraph above.
 */
enum DocumentPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::Normal => 'Medium',
            self::High, self::Urgent => 'High',
        };
    }

    /** The full sentence the Submit Document form offers, from the client's slip. */
    public function optionLabel(): string
    {
        return match ($this) {
            self::High, self::Urgent => 'High - Must be done within 24 hours.',
            self::Normal => 'Medium - Within the week.',
            self::Low => 'Low - Whenever it is possible.',
        };
    }

    /**
     * The stored values a filter for this level has to find. A legacy urgent
     * document reads High, so asking for High must not leave it out.
     *
     * @return list<string>
     */
    public function storedValues(): array
    {
        return $this === self::High
            ? [self::High->value, self::Urgent->value]
            : [$this->value];
    }

    /**
     * What a new document may be filed as, most pressing first -- the order the
     * client's slip lists them in.
     *
     * @return list<self>
     */
    public static function selectable(): array
    {
        return [self::High, self::Normal, self::Low];
    }

    /** Higher sorts first in queues. */
    public function weight(): int
    {
        return match ($this) {
            self::Low => 0,
            self::Normal => 1,
            self::High => 2,
            self::Urgent => 3,
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Low => 'slate',
            self::Normal => 'sky',
            // Same colour as the word: a legacy urgent row reads High, so it
            // must not be the one High pill in a different colour.
            self::High, self::Urgent => 'amber',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
