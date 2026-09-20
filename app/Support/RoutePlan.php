<?php

namespace App\Support;

use App\Models\Office;

/**
 * How long a §9 routing plan may be.
 *
 * WHY THIS IS NOT JUST `max:20` ANY MORE. It was, and on 2026-09-20 the client
 * hit it: they picked all 52 departments and got "The department field must
 * not have more than 20 items." They asked for the cap to go, with duplicates
 * still refused. Twenty was never a domain rule -- nothing breaks at
 * twenty-one -- it was a guess at "nobody would route further than this", and
 * an LGU that wants a circular sent to every department proved it wrong.
 *
 * SO WHY A CAP AT ALL. `array` with no ceiling means a posted list of 100,000
 * ids gets a `distinct` scan and an `exists` lookup EACH, on an endpoint any
 * signed-in clerk can reach. The ceiling below is not a policy about routing;
 * it is the point past which a request cannot be honest.
 *
 * The number of ACTIVE OFFICES is exactly that point. `distinct` already
 * forbids repeats and `exists` already forbids anything that is not an active
 * office, so a legitimate plan can never be longer than this, and every
 * request that is has already been refused for another reason. To the person
 * filling in the form the list is unlimited: they can pick every department
 * there is, which is all "unlimited" ever meant here.
 */
final class RoutePlan
{
    /**
     * Floor of 1 so the rule is never `max:0`, which would refuse every
     * submit -- including the one that would tell somebody the office list is
     * empty. An installation with no active offices has a seeding problem, and
     * "required" is the message that describes it.
     */
    public static function maxOffices(): int
    {
        return max(1, Office::query()->active()->count());
    }

    /**
     * The message for the cap, written for a records clerk rather than for
     * the validator.
     *
     * It can only ever be seen by a hand-built request, since the picker
     * offers each department once -- so it says what is actually wrong rather
     * than quoting a number the person never chose.
     */
    public static function tooManyMessage(): string
    {
        return 'That is more departments than exist. Each department can be added once.';
    }
}
