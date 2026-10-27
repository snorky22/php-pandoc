<?php

namespace Pandoc\Reader\Odt;

/**
 * Resolves ODF styles: character formatting through the parent chain, paragraph properties,
 * list level formats and the page text width. Names are kept as written in the file
 * ("Heading_20_1"); {@see chain()} also returns them decoded and lower-cased ("heading 1").
 */
final class OdtStyles
{
    public const NS = [
        'office' => 'urn:oasis:names:tc:opendocument:xmlns:office:1.0',
        'style' => 'urn:oasis:names:tc:opendocument:xmlns:style:1.0',
        'text' => 'urn:oasis:names:tc:opendocument:xmlns:text:1.0',
        'table' => 'urn:oasis:names:tc:opendocument:xmlns:table:1.0',
        'draw' => 'urn:oasis:names:tc:opendocument:xmlns:drawing:1.0',
        'fo' => 'urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0',
        'xlink' => 'http://www.w3.org/1999/xlink',
        'svg' => 'urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0',
        'meta' => 'urn:oasis:names:tc:opendocument:xmlns:meta:1.0',
        'dc' => 'http://purl.org/dc/elements/1.1/',
    ];

    /** @var array<string, array{parent:?string, display:string, text:array, para:array}> */
    private array $styles = [];
    /** @var array<string, array<int, array{type:string, format:string, prefix:string, suffix:string, start:int}>> */
    private array $lists = [];
    public float $textWidthCm = 16.0;
    public ?string $language = null;

    public static function xpath(\DOMDocument $doc): \DOMXPath
    {
        $x = new \DOMXPath($doc);
        foreach (self::NS as $p => $u) {
            $x->registerNamespace($p, $u);
        }
        return $x;
    }

    public static function attr(\DOMElement $e, string $prefix, string $name): ?string
    {
        return $e->hasAttributeNS(self::NS[$prefix], $name) ? $e->getAttributeNS(self::NS[$prefix], $name) : null;
    }

    /** Loads the styles of a content.xml or styles.xml document (call for both). */
    public function load(\DOMDocument $doc): void
    {
        $x = self::xpath($doc);
        foreach ($x->query('//style:style') as $s) {
            $name = self::attr($s, 'style', 'name');
            if ($name === null) {
                continue;
            }
            $this->styles[$name] = [
                'parent' => self::attr($s, 'style', 'parent-style-name'),
                'display' => self::attr($s, 'style', 'display-name') ?? '',
                'text' => $this->textProps($x->query('style:text-properties', $s)->item(0)),
                'para' => $this->paraProps($x->query('style:paragraph-properties', $s)->item(0)),
            ];
        }
        foreach ($x->query('//style:default-style[@style:family="paragraph"]/style:text-properties') as $tp) {
            $lang = self::attr($tp, 'fo', 'language');
            if ($lang !== null && $lang !== 'zxx' && $this->language === null) {
                $country = self::attr($tp, 'fo', 'country');
                $this->language = $country !== null && $country !== 'none' ? "$lang-$country" : $lang;
            }
        }
        foreach ($x->query('//text:list-style') as $ls) {
            $name = self::attr($ls, 'style', 'name');
            if ($name === null) {
                continue;
            }
            foreach ($ls->childNodes as $lv) {
                if (!$lv instanceof \DOMElement || self::attr($lv, 'text', 'level') === null) {
                    continue;
                }
                $this->lists[$name][(int) self::attr($lv, 'text', 'level')] = [
                    'type' => $lv->localName === 'list-level-style-number' ? 'number' : 'bullet',
                    'format' => self::attr($lv, 'style', 'num-format') ?? '',
                    'prefix' => self::attr($lv, 'style', 'num-prefix') ?? '',
                    'suffix' => self::attr($lv, 'style', 'num-suffix') ?? '',
                    'start' => (int) (self::attr($lv, 'text', 'start-value') ?? 1),
                ];
            }
        }
        $pl = $x->query('//style:page-layout-properties')->item(0);
        if ($pl instanceof \DOMElement && $doc->documentElement->localName === 'document-styles') {
            $w = self::cm(self::attr($pl, 'fo', 'page-width') ?? '');
            if ($w !== null) {
                $ml = self::cm(self::attr($pl, 'fo', 'margin-left') ?? '') ?? 0.0;
                $mr = self::cm(self::attr($pl, 'fo', 'margin-right') ?? '') ?? 0.0;
                $this->textWidthCm = max(5.0, $w - $ml - $mr);
            }
        }
    }

    /** Converts "2.5cm", "10mm", "1in", "12pt", "96px" to centimetres. */
    public static function cm(string $v): ?float
    {
        if (!preg_match('/^(-?[0-9.]+)\s*(cm|mm|in|pt|px|pc)?$/', trim($v), $m)) {
            return null;
        }
        $n = (float) $m[1];
        return match ($m[2] ?? 'cm') {
            'mm' => $n / 10,
            'in' => $n * 2.54,
            'pt' => $n / 72 * 2.54,
            'px' => $n / 96 * 2.54,
            'pc' => $n / 6 * 2.54,
            default => $n,
        };
    }

    private function textProps(?\DOMNode $tp): array
    {
        $p = [];
        if (!$tp instanceof \DOMElement) {
            return $p;
        }
        if (($w = self::attr($tp, 'fo', 'font-weight')) !== null) {
            $p['bold'] = in_array($w, ['bold', '600', '700', '800', '900'], true);
        }
        if (($i = self::attr($tp, 'fo', 'font-style')) !== null) {
            $p['italic'] = in_array($i, ['italic', 'oblique'], true);
        }
        if (($u = self::attr($tp, 'style', 'text-underline-style')) !== null) {
            $p['underline'] = $u !== 'none';
        }
        if (($s = self::attr($tp, 'style', 'text-line-through-style')) !== null) {
            $p['strike'] = $s !== 'none';
        }
        if (($pos = self::attr($tp, 'style', 'text-position')) !== null) {
            $first = strtok(trim($pos), ' ');
            $p['position'] = match (true) {
                $first === 'super' || (is_numeric(rtrim((string) $first, '%')) && (float) $first > 0) => 'super',
                $first === 'sub' || (is_numeric(rtrim((string) $first, '%')) && (float) $first < 0) => 'sub',
                default => null,
            };
        }
        if (($v = self::attr($tp, 'fo', 'font-variant')) !== null) {
            $p['smallcaps'] = $v === 'small-caps';
        }
        if (($c = self::attr($tp, 'fo', 'color')) !== null) {
            $p['color'] = strtolower($c);
        }
        if (($b = self::attr($tp, 'fo', 'background-color')) !== null) {
            $p['background'] = $b === 'transparent' ? null : strtolower($b);
        }
        $font = (self::attr($tp, 'style', 'font-name') ?? '') . ' ' . (self::attr($tp, 'fo', 'font-family') ?? '');
        if (trim($font) !== '') {
            $p['mono'] = (bool) preg_match('/mono|courier|consolas|menlo|typewriter|source code/i', $font);
        }
        return $p;
    }

    private function paraProps(?\DOMNode $pp): array
    {
        $p = [];
        if (!$pp instanceof \DOMElement) {
            return $p;
        }
        if (($a = self::attr($pp, 'fo', 'text-align')) !== null) {
            $p['align'] = match ($a) {
                'center' => 'center',
                'end', 'right' => 'right',
                'justify' => 'justify',
                default => 'left',
            };
        }
        if (($m = self::attr($pp, 'fo', 'margin-left')) !== null) {
            $p['marginLeft'] = self::cm($m) ?? 0.0;
        }
        return $p;
    }

    /** Style names from the style itself up to its root, as written. */
    public function ancestry(?string $name): array
    {
        $names = [];
        while ($name !== null && $name !== '' && isset($this->styles[$name]) && !in_array($name, $names, true)) {
            $names[] = $name;
            $name = $this->styles[$name]['parent'];
        }
        return $names;
    }

    public function parent(?string $name): ?string
    {
        return $name !== null && isset($this->styles[$name]) ? $this->styles[$name]['parent'] : null;
    }

    /** Decoded, lower-cased names (style name and display name) of the style and its ancestors. */
    public function chain(?string $name): array
    {
        $out = [];
        foreach ($this->ancestry($name) as $n) {
            $out[] = mb_strtolower(str_replace('_20_', ' ', $n));
            if ($this->styles[$n]['display'] !== '') {
                $out[] = mb_strtolower($this->styles[$n]['display']);
            }
        }
        return array_values(array_unique($out));
    }

    /** True when the style or one of its ancestors has one of the given decoded names. */
    public function isA(?string $name, string ...$names): bool
    {
        return (bool) array_intersect($this->chain($name), $names);
    }

    /** Character formatting after inheritance; keys are only present when set somewhere. */
    public function text(?string $name): array
    {
        $merged = [];
        foreach (array_reverse($this->ancestry($name)) as $n) {
            $merged = array_replace($merged, $this->styles[$n]['text']);
        }
        return $merged;
    }

    /** Paragraph properties after inheritance. */
    public function para(?string $name): array
    {
        $merged = [];
        foreach (array_reverse($this->ancestry($name)) as $n) {
            $merged = array_replace($merged, $this->styles[$n]['para']);
        }
        return $merged;
    }

    /** Character formatting set by the style itself, not inherited (direct formatting of a paragraph). */
    public function ownText(?string $name): array
    {
        return $name !== null && isset($this->styles[$name]) ? $this->styles[$name]['text'] : [];
    }

    public function isAutomatic(?string $name): bool
    {
        return $name !== null && isset($this->styles[$name]) && $this->styles[$name]['display'] === ''
            && !str_contains($name, '_20_') && preg_match('/^[A-Z]{1,3}\d+$/', $name) === 1;
    }

    /** @return array{type:string, format:string, prefix:string, suffix:string, start:int}|null */
    public function listLevel(?string $listStyle, int $level): ?array
    {
        return $listStyle !== null ? ($this->lists[$listStyle][$level] ?? null) : null;
    }
}
