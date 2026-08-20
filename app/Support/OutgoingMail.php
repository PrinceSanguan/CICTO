<?php

namespace App\Support;

/**
 * Can a message actually leave this server?
 *
 * One predicate, in one place, because the answer changes what several screens
 * are allowed to say. `log` and `array` are the two transports that accept
 * everything and deliver nothing: the send succeeds, the caller gets no error,
 * and no human ever receives it. A page that reports success on either is
 * lying, and the lie is expensive -- somebody waits for a password-reset email
 * that will never arrive.
 *
 * This used to be a private method on HelpController with a second copy inlined
 * in HostCheckCommand. Two copies of a predicate are one drift away from a
 * screen that says mail works while the probe says it does not, so they are
 * both this now.
 *
 * CLIENT QUESTION B3, answered 2026-08-20: CICTO will not supply SMTP
 * credentials, and recommends an external service such as Google SMTP instead.
 * So `log` remains what ships, this keeps returning false until an operator
 * sets MAIL_MAILER, and the administrator-set-password module on
 * /super-admin/users is the supported way back into a locked-out account.
 */
final class OutgoingMail
{
    /**
     * The transports that accept everything and deliver nothing.
     *
     * `array` is here as well as `log` because it is what phpunit.xml pins, and
     * a test suite is exactly where "mail appeared to send" must not be true.
     */
    private const NULL_TRANSPORTS = ['log', 'array'];

    /** Whether mail sent by this application can reach a person. */
    public static function isConfigured(): bool
    {
        return ! in_array(self::mailer(), self::NULL_TRANSPORTS, true);
    }

    /** The configured default mailer, for reporting rather than deciding. */
    public static function mailer(): string
    {
        return (string) config('mail.default');
    }

    /** The address every message claims to be from. */
    public static function fromAddress(): string
    {
        return (string) config('mail.from.address');
    }

    /**
     * Whether the From address is still the framework's placeholder.
     *
     * Worth its own question because it is a silent failure on exactly the
     * service the client recommended: Google SMTP refuses to send as an address
     * that is not the authenticated account, so a deployment that sets
     * MAIL_USERNAME and forgets MAIL_FROM_ADDRESS authenticates fine and then
     * has every message rejected at send time.
     */
    public static function fromAddressIsPlaceholder(): bool
    {
        return in_array(self::fromAddress(), ['', 'hello@example.com'], true);
    }
}
