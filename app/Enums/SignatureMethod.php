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

    /**
     * An image of the signer's signature, dragged onto the pad or picked from
     * disk -- the client's request of 2026-09-19. Stored exactly like a drawn
     * mark: the browser redraws the picture onto a canvas and sends a PNG, so
     * SignDocument's PNG-only check applies unchanged and whatever the
     * original file carried besides pixels (EXIF, an SVG's scripts) never
     * reaches the server. Recorded as its own method rather than passed off as
     * drawn, because the certificate hash covers the method and the record
     * should say how the mark was actually made.
     */
    case Uploaded = 'uploaded';

    public function label(): string
    {
        return match ($this) {
            self::Drawn => 'Drawn',
            self::Typed => 'Typed',
            self::Uploaded => 'Uploaded image',
        };
    }

    public function requiresImage(): bool
    {
        return $this === self::Drawn || $this === self::Uploaded;
    }

    /** The sentence for a signing submit that arrived without its image. */
    public function missingImageMessage(): string
    {
        return $this === self::Uploaded
            ? 'Please add an image of your signature before signing.'
            : 'Please draw your signature before signing.';
    }
}
