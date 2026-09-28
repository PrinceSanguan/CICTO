<?php

namespace App\Support;

use App\Models\Office;
use App\Models\User;

/**
 * Who a Confidential document is for (client, DTS_Office_Routing_Paths.pdf,
 * 2026-09-25): "City Mayor / HRMO only -- restricted, bypasses normal
 * multi-office routing".
 *
 * THE RULE, in the three places it is enforced:
 *
 *  - WHERE IT MAY GO. Straight from the office filing it to ONE of these
 *    offices, and on from there only to another of them. StoreDocumentRequest
 *    and TransitionDocumentRequest refuse anything else, and RegisterDocument
 *    sends it on at once, so the filing office's Admin never has to receive it
 *    -- that is the "bypasses normal routing".
 *
 *  - WHO SEES IT. The person who filed it, and the people of one of these
 *    offices once it has been there. DocumentBuilder::visibleTo and
 *    DocumentPolicy::view both ask trustsUser(). NOT the rest of the
 *    filing office -- a complaint about a department head must not land in
 *    that head's list -- and NOT a Super Admin, which is what "City Mayor /
 *    HRMO only" says.
 *
 *  - WHO IS TOLD. The bell and the email go only to people who can open it:
 *    NotificationWriter and DocumentMailer ask the policy for these documents.
 *
 * The offices are config (`cicto.confidential.offices`, env
 * CICTO_CONFIDENTIAL_OFFICES), by code, so the list can change without a
 * deploy.
 */
final class Confidential
{
    /** @return list<string> */
    public static function officeCodes(): array
    {
        /** @var list<string> $codes */
        $codes = (array) config('cicto.confidential.offices', ['OCM', 'HRMO']);

        return $codes;
    }

    /**
     * The active offices a Confidential document may go to, in the form's
     * order.
     *
     * @return list<int>
     */
    public static function officeIds(): array
    {
        return array_values(Office::query()
            ->active()
            ->ordered()
            ->whereIn('code', self::officeCodes())
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all());
    }

    public static function trusts(?int $officeId): bool
    {
        return $officeId !== null && in_array($officeId, self::officeIds(), true);
    }

    /**
     * Does this person work at one of the offices? Read off their own office
     * relation, which the model keeps once loaded -- this is asked for every
     * list and every permission on a page.
     */
    public static function trustsUser(User $user): bool
    {
        $office = $user->office;

        return $office !== null
            && $office->is_active
            && in_array($office->code, self::officeCodes(), true);
    }

    /**
     * "the Office of the City Mayor or the Office of the City Human Resource
     * Management Officer", for messages.
     */
    public static function officeNames(): string
    {
        $names = Office::query()->whereKey(self::officeIds())->ordered()->pluck('name')->all();

        if ($names === []) {
            return 'the City Mayor or HRMO';
        }

        $last = array_pop($names);

        return $names === [] ? $last : implode(', ', $names).' or '.$last;
    }
}
