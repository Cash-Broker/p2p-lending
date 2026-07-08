<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;

/**
 * Normalizes uploaded KYC images to formats the whole pipeline can render.
 *
 * iPhones shoot HEIC by default. When a site's accept list doesn't name HEIC
 * explicitly, iOS transcodes the photo on the fly during the pick — and that
 * transcoder produces broken/black files on some devices (observed in prod,
 * 2026-07-08: every gallery AND camera pick arrived black). The fix is to
 * request the ORIGINAL file (accept includes image/heic) and convert it to
 * JPEG here with ImageMagick, where the conversion is observable and tested.
 *
 * Requires php-imagick with a HEIC delegate (libheif) in production; when
 * unavailable, callers must reject HEIC uploads with a clear message instead
 * of storing files the admin can't view.
 */
class KycImageNormalizer
{
    private const JPEG_QUALITY = 85;

    public function isHeic(UploadedFile $file): bool
    {
        $mime = strtolower((string) $file->getMimeType()); // content-sniffed
        $ext = strtolower($file->getClientOriginalExtension());

        return str_contains($mime, 'heic') || str_contains($mime, 'heif')
            || in_array($ext, ['heic', 'heif'], true);
    }

    public function heicSupported(): bool
    {
        if (! extension_loaded('imagick')) {
            return false;
        }

        return (new \Imagick())->queryFormats('HEI*') !== [];
    }

    /**
     * Convert a HEIC upload to JPEG binary, honouring EXIF orientation so ID
     * documents don't arrive sideways.
     */
    public function toJpeg(UploadedFile $file): string
    {
        $image = new \Imagick($file->getRealPath());

        // Multi-frame HEIC (bursts/live photos) — keep the primary frame.
        $image->setIteratorIndex(0);
        $this->autoOrient($image);
        $image->setImageFormat('jpeg');
        $image->setImageCompressionQuality(self::JPEG_QUALITY);

        try {
            return $image->getImageBlob();
        } finally {
            $image->clear();
        }
    }

    private function autoOrient(\Imagick $image): void
    {
        switch ($image->getImageOrientation()) {
            case \Imagick::ORIENTATION_TOPRIGHT: $image->flopImage(); break;
            case \Imagick::ORIENTATION_BOTTOMRIGHT: $image->rotateImage('#000', 180); break;
            case \Imagick::ORIENTATION_BOTTOMLEFT: $image->flopImage(); $image->rotateImage('#000', 180); break;
            case \Imagick::ORIENTATION_LEFTTOP: $image->flopImage(); $image->rotateImage('#000', -90); break;
            case \Imagick::ORIENTATION_RIGHTTOP: $image->rotateImage('#000', 90); break;
            case \Imagick::ORIENTATION_RIGHTBOTTOM: $image->flopImage(); $image->rotateImage('#000', 90); break;
            case \Imagick::ORIENTATION_LEFTBOTTOM: $image->rotateImage('#000', -90); break;
        }
        $image->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
    }
}
