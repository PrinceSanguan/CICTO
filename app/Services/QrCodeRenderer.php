<?php

namespace App\Services;

use App\Models\Document;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * §7 QR generation.
 *
 * SVG backend specifically: it needs only ext-xml/XMLWriter, with no GD and no
 * Imagick, which is what makes it safe to rely on for shared LGU hosting.
 *
 * Generated on the fly and never stored. Rendering is sub-millisecond; storing
 * a few thousand SVGs would buy a cache-invalidation problem, a backup problem
 * and a filesystem-permissions problem in exchange for nothing.
 *
 * NOTE on the library version: in bacon-qr-code 3.1 ErrorCorrectionLevel is a
 * DASPRiD\Enum, not a native PHP enum, so the correct call is
 * ErrorCorrectionLevel::M() with parentheses.
 */
class QrCodeRenderer
{
    /**
     * Error correction M (~15%), margin 4 modules.
     *
     * M rather than H: the label is protected by tape indoors, not weather. M
     * keeps a ~56-character URL at QR version 3, so a 25 mm printed code has
     * ~0.86 mm modules -- comfortably above the floor for a phone camera at
     * 300 dpi. H would push to version 4-5 and shrink the modules for no
     * practical gain.
     */
    public function svg(string $data, int $size = 320): string
    {
        $writer = new Writer(new ImageRenderer(
            new RendererStyle($size, 4),
            new SvgImageBackEnd,
        ));

        return $writer->writeString(
            $data,
            Encoder::DEFAULT_BYTE_MODE_ENCODING,
            ErrorCorrectionLevel::M(),
        );
    }

    public function forDocument(Document $document, int $size = 320): string
    {
        return $this->svg($this->urlFor($document), $size);
    }

    /**
     * What the QR actually encodes: an absolute HTTPS URL to the scan path,
     * carrying nothing but the opaque token.
     *
     * Not the control number (sequential, so the whole register would be
     * enumerable by incrementing, and it is already printed in text on the
     * label anyway). Not a Laravel signed URL either -- the signature would be
     * taped to the same folder as the thing it protects, so it authenticates
     * nothing an attacker lacks, adds ~70 characters of QR density, and an
     * expiry cannot be honoured by paper.
     *
     * The base comes from config, never from the request: behind a reverse
     * proxy url() often emits http:// or an internal hostname, and that would
     * be printed onto paper.
     */
    public function urlFor(Document $document): string
    {
        $base = rtrim((string) config('cicto.scan_base_url'), '/');

        return $base.'/s/'.$document->qr_token;
    }
}
