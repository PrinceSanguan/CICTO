<?php

namespace App\Support;

use App\Models\DocumentType;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * Spec §11. The single source of the deadline boundary.
 *
 * Both the SQL predicate (DocumentBuilder::overdue) and the PHP badge
 * (Document::dueState) read their boundary from here, so a row's badge can
 * never disagree with the query that selected it.
 *
 * Calendar days, not working days: a working-day engine needs a holidays table
 * reseeded every year, and an unseeded table silently degrades to calendar days
 * while still reporting SLA figures -- a worse failure than not offering it.
 *
 * Everything returns CarbonInterface, because AppServiceProvider installs
 * CarbonImmutable as the date class and that is not a subclass of
 * Illuminate\Support\Carbon.
 */
final class Deadlines
{
    /**
     * The one clock.
     *
     * Bound into queries as a parameter rather than emitting SQL NOW(), so PHP
     * time and database-server time cannot drift apart under APP_TIMEZONE --
     * and so Carbon::setTestNow() actually travels in tests.
     */
    public static function now(): CarbonInterface
    {
        return Date::now();
    }

    /** Documents due at or before this instant are "approaching". */
    public static function warnBoundary(): CarbonInterface
    {
        return self::now()->addDays(self::approachingDays());
    }

    public static function approachingDays(): int
    {
        return max(0, (int) config('cicto.deadlines.approaching_days', 3));
    }

    public static function defaultTurnaroundDays(): int
    {
        return max(1, (int) config('cicto.deadlines.default_turnaround_days', 3));
    }

    /**
     * Expected completion for a document of this type, measured from $from.
     *
     * Clamped to the close of business so a deadline never lands at 03:00 and
     * reads as overdue to someone who had the whole working day.
     */
    public static function dueAt(?DocumentType $type, ?CarbonInterface $from = null): CarbonInterface
    {
        $days = $type->turnaround_days ?? self::defaultTurnaroundDays();
        $endHour = (int) config('cicto.deadlines.business_end_hour', 18);

        return ($from ?? self::now())
            ->addDays(max(1, $days))
            ->setTime($endHour, 0);
    }

    /**
     * Per-leg SLA. Never later than the document's overall due date -- an office
     * cannot still be "on time" past the date the citizen was quoted.
     */
    public static function legDueAt(
        ?DocumentType $type,
        ?CarbonInterface $documentDueAt,
        ?CarbonInterface $from = null,
    ): CarbonInterface {
        $legDue = self::dueAt($type, $from);

        if ($documentDueAt instanceof CarbonInterface && $documentDueAt->lessThan($legDue)) {
            return $documentDueAt;
        }

        return $legDue;
    }
}
