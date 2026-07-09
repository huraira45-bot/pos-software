<?php

namespace App\Services\Receipt;

use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * Used only by the PDF fallback receipt - the thermal (ESC/POS) path sends the
 * raw QR payload string to the local print-agent, which renders the QR itself
 * on the printer.
 */
class QrCodeGenerator
{
    /** @return string data: URI, ready to drop straight into an <img src="">. */
    public function toBase64DataUri(string $data): string
    {
        $options = new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'imageBase64' => true,
            'scale' => 6,
            'imageTransparent' => false,
        ]);

        return (new QRCode($options))->render($data);
    }
}
