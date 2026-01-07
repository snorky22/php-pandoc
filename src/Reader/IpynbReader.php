<?php

namespace Pandoc\Reader;

use Pandoc\AST\Attr;
use Pandoc\AST\CodeBlock;
use Pandoc\AST\Div;
use Pandoc\AST\Meta;
use Pandoc\AST\Pandoc;
use Pandoc\AST\RawBlock;
use Pandoc\AST\Format;
use Pandoc\AST\Image;
use Pandoc\AST\Para;
use Pandoc\AST\Target;
use Pandoc\AST\MediaBag;

class IpynbReader implements ReaderInterface
{
    use HasMediaBag;

    private MarkdownReader $markdownReader;

    public function __construct()
    {
        $this->markdownReader = new MarkdownReader();
        $this->initMediaBag();
    }

    public function read(string $content): Pandoc
    {
        $this->initMediaBag();
        $data = json_decode($content, true);
        if ($data === null) {
            throw new \Exception("Invalid JSON in Jupyter Notebook");
        }

        $cells = $data['cells'] ?? [];
        $blocks = [];

        foreach ($cells as $cell) {
            $cellType = $cell['cell_type'] ?? '';
            $source = $cell['source'] ?? '';
            if (is_array($source)) {
                $source = implode('', $source);
            }

            // Handle attachments
            $attachments = $cell['attachments'] ?? [];
            foreach ($attachments as $filename => $mimeData) {
                foreach ($mimeData as $mime => $base64) {
                    $this->mediaBag->insert($filename, $mime, base64_decode($base64));
                    // We only take the first mime type for simplicity
                    break;
                }
            }

            $metadata = $cell['metadata'] ?? [];
            $ident = $cell['id'] ?? '';
            $classes = ['cell', $cellType];

            // Convert metadata to key-value pairs for Attr
            $attributes = [];
            foreach ($metadata as $key => $value) {
                if (is_scalar($value)) {
                    $attributes[] = [$key, (string)$value];
                }
            }

            $attr = new Attr($ident, $classes, $attributes);

            if ($cellType === 'markdown') {
                // Adjust attachment references in markdown
                $source = preg_replace('/attachment:([^)]+)/', '$1', $source);
                $cellDoc = $this->markdownReader->read($source);
                $blocks[] = new Div($attr, $cellDoc->blocks);
            } elseif ($cellType === 'code') {
                $blocks[] = new Div($attr, [
                    new CodeBlock($attr, $source)
                ]);

                // Handle outputs
                $outputs = $cell['outputs'] ?? [];
                foreach ($outputs as $output) {
                    $dataOutput = $output['data'] ?? [];
                    foreach ($dataOutput as $mime => $contentOutput) {
                        if (str_starts_with($mime, 'image/')) {
                            $ext = explode('/', $mime)[1];
                            $filename = 'output_' . md5(serialize($contentOutput)) . '.' . $ext;
                            if (is_array($contentOutput)) {
                                $contentOutput = implode('', $contentOutput);
                            }
                            $this->mediaBag->insert($filename, $mime, base64_decode($contentOutput));
                            // Add an Image node in a Para block for the output
                            $blocks[] = new Para([
                                new Image(new Attr(), [], new Target($filename))
                            ]);
                            // We only take the first image mime type for this output
                            break;
                        }
                    }
                }
            } elseif ($cellType === 'raw') {
                $format = $metadata['format'] ?? 'ipynb';
                $blocks[] = new Div($attr, [
                    new RawBlock(new Format($format), $source)
                ]);
            }
        }

        return new Pandoc(new Meta(), $blocks, $this->mediaBag);
    }
}
