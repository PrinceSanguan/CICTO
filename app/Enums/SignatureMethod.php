<?php

namespace App\Enums;

/**
 * How the signature mark was captured.
 *
 * Neither method changes what the signature legally IS -- see the client
 * paragraph in docs/implementation/phase-3-trust-and-toolchain.md. This only
 * records how the visible mark was produced.
 */
enum SignatureMethod: string
{
    /** Drawn on a canvas with a finger, stylus or mouse. Stored as PNG. */
    case Drawn = 'drawn';

    /** Typed name rendered in a script face. For mouse-only desktops. */
    case Typed = 'typed';

    public function label(): string
    {
        return match ($this) {
            self::Drawn => 'Drawn',
            self::Typed => 'Typed',
        };
    }

    public function requiresImage(): bool
    {
        return $this === self::Drawn;
    }
}
