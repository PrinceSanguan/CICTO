<?php

namespace App\Support;

/**
 * The opaque token a QR label resolves on.
 *
 * 26 characters of Crockford Base32 = 130 bits of CSPRNG randomness.
 *
 * Lowercase only, deliberately: MySQL's default collation is case-insensitive
 * and PostgreSQL's is not, so a mixed-case token would have different
 * uniqueness and lookup semantics on the two drivers this project must support.
 *
 * Not a ULID: a ULID's 48-bit timestamp prefix leaks document creation ordering
 * to anyone who photographs two labels, and 80 bits of randomness is thin for
 * an identifier that gets printed once and lives on paper for years.
 */
final class QrToken
{
    /** Crockford Base32 -- no i, l, o or u, so it survives being read aloud. */
    private const ALPHABET = '0123456789abcdefghjkmnpqrstvwxyz';

    public const LENGTH = 26;

    public static function generate(): string
    {
        $token = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $token .= self::ALPHABET[random_int(0, 31)];
        }

        return $token;
    }

    public static function isValid(string $token): bool
    {
        return mb_strlen($token) === self::LENGTH
            && preg_match('/^['.self::ALPHABET.']+$/', $token) === 1;
    }
}
