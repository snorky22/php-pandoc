<?php

namespace Pandoc\Writer;

/**
 * Shrinks raster images with Imagick before they are embedded in an EPUB.
 *
 * - images wider than $maxWidth are downscaled (Lanczos), metadata is stripped;
 * - images that really use transparency, or have few colours (diagrams, line art), stay PNG;
 * - opaque images with many colours (photos, renders) are re-encoded as JPEG;
 * - the original bytes are kept whenever the result would not be smaller.
 *
 * SVG and GIF (possibly animated) are left alone. Without the Imagick extension
 * every call returns null, so callers simply keep the original.
 */
class ImageOptimizer
{
    /** Opaque images with more distinct colours than this are treated as photographic. */
    private const PHOTO_COLOR_THRESHOLD = 4096;

    public function __construct(
        private int $maxWidth = 1600,
        private int $jpegQuality = 85
    ) {}

    public static function isAvailable(): bool
    {
        return extension_loaded('imagick') && class_exists(\Imagick::class);
    }

    /** @return array{contents:string, mime:string}|null null when nothing better could be produced */
    public function optimize(string $contents, string $mime): ?array
    {
        if (!self::isAvailable() || !in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            return null;
        }
        try {
            $im = new \Imagick();
            $im->readImageBlob($contents);
            $im->setIteratorIndex(0);

            if ($this->maxWidth > 0 && $im->getImageWidth() > $this->maxWidth) {
                $im->resizeImage($this->maxWidth, 0, \Imagick::FILTER_LANCZOS, 1);
            }
            $im->stripImage();
            $im->setImageDepth(8);

            $result = $mime === 'image/jpeg' ? $this->encodeJpeg($im, false) : $this->encodeSmart($im);
            $im->clear();
        } catch (\Throwable) {
            return null;
        }
        return strlen($result['contents']) < strlen($contents) ? $result : null;
    }

    /** @return array{contents:string, mime:string} */
    private function encodeSmart(\Imagick $im): array
    {
        if ($this->usesTransparency($im) || $im->getImageColors() <= self::PHOTO_COLOR_THRESHOLD) {
            return $this->encodePng($im);
        }
        return $this->encodeJpeg($im, true);
    }

    private function usesTransparency(\Imagick $im): bool
    {
        if (!$im->getImageAlphaChannel()) {
            return false;
        }
        $range = $im->getImageChannelRange(\Imagick::CHANNEL_ALPHA);
        return $range['minima'] < \Imagick::getQuantumRange()['quantumRangeLong'];
    }

    /** @return array{contents:string, mime:string} */
    private function encodePng(\Imagick $im): array
    {
        $im->setImageFormat('png');
        $im->setOption('png:compression-level', '9');
        $im->setOption('png:compression-filter', '5');
        return ['contents' => $im->getImageBlob(), 'mime' => 'image/png'];
    }

    /** @return array{contents:string, mime:string} */
    private function encodeJpeg(\Imagick $im, bool $flatten): array
    {
        if ($flatten) {
            $im->setImageBackgroundColor('white');
            $im->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
        }
        $im->setImageFormat('jpeg');
        $im->setImageCompression(\Imagick::COMPRESSION_JPEG);
        $im->setImageCompressionQuality($this->jpegQuality);
        $im->setInterlaceScheme(\Imagick::INTERLACE_PLANE);
        return ['contents' => $im->getImageBlob(), 'mime' => 'image/jpeg'];
    }
}
