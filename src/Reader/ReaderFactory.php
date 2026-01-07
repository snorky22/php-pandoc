<?php

namespace Pandoc\Reader;

class ReaderFactory
{
    /**
     * Creates the appropriate reader for the given file extension.
     *
     * @param string $extension
     * @return ReaderInterface
     * @throws \Exception
     */
    public static function createForExtension(string $extension): ReaderInterface
    {
        return match (strtolower($extension)) {
            'md', 'markdown' => new MarkdownReader(),
            'docx' => new DocxReader(),
            'html', 'htm' => new HtmlReader(),
            'ipynb' => new IpynbReader(),
            default => throw new \Exception("Unsupported file extension: .$extension")
        };
    }
}
