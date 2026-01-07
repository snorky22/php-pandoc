<?php

namespace Pandoc\AST;

class MediaBag
{
    /** @var array<string, array{mime: string, contents: string}> */
    private array $items = [];

    public function insert(string $path, string $mime, string $contents): void
    {
        $this->items[$path] = [
            'mime' => $mime,
            'contents' => $contents,
        ];
    }

    public function lookup(string $path): ?array
    {
        return $this->items[$path] ?? null;
    }

    /** @return array<string, array{mime: string, contents: string}> */
    public function getAll(): array
    {
        return $this->items;
    }

    public function isEmpty(): bool
    {
        return empty($this->items);
    }
}
