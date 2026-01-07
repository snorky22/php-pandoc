<?php

namespace Pandoc\Reader;

use Pandoc\AST\MediaBag;

trait HasMediaBag
{
    protected MediaBag $mediaBag;

    protected function initMediaBag(): void
    {
        $this->mediaBag = new MediaBag();
    }

    protected function parseDataUri(string $uri): ?array
    {
        if (preg_match('/^data:([^;]+);base64,(.+)$/', $uri, $matches)) {
            $mime = $matches[1];
            $base64 = $matches[2];
            $ext = explode('/', $mime)[1] ?? 'png';
            $filename = 'image_' . md5($base64) . '.' . $ext;
            return [
                'filename' => $filename,
                'mime' => $mime,
                'contents' => base64_decode($base64)
            ];
        }
        return null;
    }
}
