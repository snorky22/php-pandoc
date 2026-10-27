<?php

namespace Pandoc\Writer;

/**
 * TeX to MathML through latex2mathml/latex2mathml-php, shared by the XHTML and ODT writers.
 */
class MathMl
{
    /**
     * @return string|null a serialized <math> element (no XML declaration), or null when the
     *                     library is missing or the formula cannot be converted
     */
    public static function fromTex(string $tex, bool $display): ?string
    {
        if (!class_exists(\Latex2MathML\Converter::class)) {
            return null;
        }
        try {
            // latex2mathml-php 1.5 does not know \arg and leaks it as text
            $tex = preg_replace('/\\\\arg(?![a-zA-Z])/', '\\\\operatorname{arg}', $tex);
            $mathml = \Latex2MathML\Converter::convert($tex, display: $display ? 'block' : 'inline');
            $dom = new \DOMDocument();
            if (@$dom->loadXML($mathml)) {
                return $dom->saveXML($dom->documentElement);
            }
        } catch (\Throwable) {
            // caller falls back to the TeX source
        }
        return null;
    }

    /**
     * Adjusts MathML for LibreOffice, which letter-spaces what HTML renderers keep together:
     * runs of single-letter upright <mi> (from \mathrm{ReLU}) are merged into one <mi>, and
     * multi-letter <mo> names (\operatorname, \arg, \max) become one upright <mi>. A table cell
     * that starts with a relation (the "= ..." column of an aligned equation) gets an empty <mrow/>
     * as its left operand; without it LibreOffice draws a red "¿" in place of the operator.
     * Plain bars (|x|, |g|_\infty) are paired into fenced groups like \left| ... \right| (LibreOffice
     * otherwise treats each one as an infix operator with missing operands). A lone operator as a script (J^*, R_{+})
     * and brackets that do not pair up, as in the half-open interval [a,b), become plain symbols,
     * since LibreOffice reads them as operators or fences with missing operands.
     */
    public static function forOffice(string $mathml): string
    {
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($mathml)) {
            return $mathml;
        }
        $xp = new \DOMXPath($dom);
        foreach (iterator_to_array($xp->query('//*[local-name()="mo"]')) as $mo) {
            if (preg_match('/^[A-Za-z]{2,}$/', $mo->textContent)) {
                $mi = $dom->createElementNS($mo->namespaceURI, 'mi', $mo->textContent);
                $mi->setAttribute('mathvariant', 'normal');
                $mo->parentNode->replaceChild($mi, $mo);
            }
        }
        foreach (iterator_to_array($xp->query('//*[*[local-name()="mi"]]')) as $parent) {
            $run = null;
            foreach (iterator_to_array($parent->childNodes) as $child) {
                $isLetter = $child instanceof \DOMElement && $child->localName === 'mi'
                    && $child->getAttribute('mathvariant') === 'normal'
                    && preg_match('/^\p{L}$/u', $child->textContent);
                if ($isLetter && $run !== null) {
                    $run->textContent .= $child->textContent;
                    $parent->removeChild($child);
                } else {
                    $run = $isLetter ? $child : null;
                }
            }
        }
        foreach (iterator_to_array($xp->query('//*[*]')) as $parent) {
            self::pairBars($dom, $parent);
        }
        foreach (iterator_to_array($xp->query('//*[local-name()="mo"]')) as $mo) {
            if (in_array($mo->textContent, ['*', '∗', '+', '−', '-', '±', '∓', '×', '⋅', '·'], true) && self::isScript($mo)) {
                self::asSymbol($dom, $mo);
            }
        }
        foreach (iterator_to_array($xp->query('//*[*]')) as $parent) {
            self::demoteUnmatchedBrackets($dom, $parent);
        }
        // An empty table cell ("a & & b" in aligned equations) shows a red "¿" and can swallow its row
        foreach (iterator_to_array($xp->query('//*[local-name()="mtd"]')) as $td) {
            $empty = true;
            foreach ($td->childNodes as $c) {
                if ($c instanceof \DOMElement && $c->localName !== 'mspace') {
                    $empty = false;
                    break;
                }
            }
            if ($empty) {
                $td->appendChild($dom->createElementNS($td->namespaceURI, 'mrow'));
            }
        }
        // A relation without a left operand (a cell that starts with "= ...", a formula that starts
        // with \ge) gets an empty <mrow/>; one with no operand at all (a cell holding just "=",
        // a group {=}) becomes literal text.
        foreach (iterator_to_array($xp->query('//*[local-name()="mtd" or local-name()="mrow" or local-name()="math"]')) as $group) {
            $els = array_values(array_filter(
                iterator_to_array($group->childNodes),
                fn($c) => $c instanceof \DOMElement && $c->localName !== 'mspace'
            ));
            if (!$els || !self::isRelation($els[0])) {
                continue;
            }
            if (count($els) === 1) {
                self::asSymbol($dom, $els[0]);
            } else {
                $group->insertBefore($dom->createElementNS($els[0]->namespaceURI, 'mrow'), $group->firstChild);
            }
        }
        return $dom->saveXML($dom->documentElement);
    }

    private const RELATIONS = ['=', '≤', '≥', '<', '>', '≈', '≠', '≡', '∼', '≃', '∈', '⊂', '⊆', '⇒', '⇔', '→', '←', '↔', '≪', '≫', '∝', '∣', '≜'];

    private static function isRelation(\DOMNode $n): bool
    {
        return $n instanceof \DOMElement && $n->localName === 'mo' && $n->getAttribute('fence') !== 'true'
            && in_array(trim($n->textContent), self::RELATIONS, true);
    }

    private static function isBar(\DOMNode $n): bool
    {
        return $n instanceof \DOMElement && $n->localName === 'mo' && $n->textContent === '|' && $n->getAttribute('fence') !== 'true';
    }

    /** A script element whose base is a bar, e.g. the closing bar of |g|_\infty. */
    private static function scriptedBar(\DOMNode $n): ?\DOMElement
    {
        if ($n instanceof \DOMElement && in_array($n->localName, ['msub', 'msup', 'msubsup'], true)) {
            foreach ($n->childNodes as $c) {
                if ($c instanceof \DOMElement) {
                    return self::isBar($c) ? $c : null;
                }
            }
        }
        return null;
    }

    /** Wraps each opening bar ... closing bar run of $parent's children in a fenced <mrow>. */
    private static function pairBars(\DOMDocument $dom, \DOMElement $parent): void
    {
        $ns = $parent->namespaceURI;
        $open = null;
        $between = [];
        foreach (iterator_to_array($parent->childNodes) as $child) {
            $plain = self::isBar($child);
            $scripted = self::scriptedBar($child);
            if (!$plain && $scripted === null) {
                if ($open !== null) {
                    $between[] = $child;
                }
                continue;
            }
            if ($open === null) {
                if ($plain) {
                    $open = $child;
                    $between = [];
                }
                continue;
            }
            $group = $dom->createElementNS($ns, 'mrow');
            $open->setAttribute('stretchy', 'true');
            $open->setAttribute('fence', 'true');
            $open->setAttribute('form', 'prefix');
            $closer = $plain ? $child : $scripted;
            $closer->setAttribute('stretchy', 'true');
            $closer->setAttribute('fence', 'true');
            $closer->setAttribute('form', 'postfix');
            $parent->insertBefore($group, $open);
            $group->appendChild($open);
            foreach ($between as $node) {
                $group->appendChild($node);
            }
            if ($plain) {
                $group->appendChild($child);
                $parent->insertBefore($group, $group->nextSibling);
            } else {
                // msub[ mrow[open ... bar], script ]: the fenced group becomes the base of the script
                $group->appendChild($scripted);
                $child->insertBefore($group, $child->firstChild);
            }
            $open = null;
            $between = [];
        }
    }

    /** True when $mo is (inside a group that is) the exponent/subscript of a script element, not its base. */
    private static function isScript(\DOMElement $mo): bool
    {
        $node = $mo;
        while ($node->parentNode instanceof \DOMElement && $node->parentNode->localName === 'mrow' && $node->parentNode->childElementCount === 1) {
            $node = $node->parentNode;
        }
        $parent = $node->parentNode;
        if (!$parent instanceof \DOMElement || !in_array($parent->localName, ['msup', 'msub', 'msubsup'], true)) {
            return false;
        }
        foreach ($parent->childNodes as $c) {
            if ($c instanceof \DOMElement) {
                return $c !== $node;
            }
        }
        return false;
    }

    /** Replaces an operator or bracket by literal text; <mtext> is the one node LibreOffice does not parse as StarMath. */
    private static function asSymbol(\DOMDocument $dom, \DOMElement $mo): void
    {
        $mo->parentNode->replaceChild($dom->createElementNS($mo->namespaceURI, 'mtext', $mo->textContent), $mo);
    }

    /** The plain bracket <mo> that $c is, or that is the base of the script element $c. */
    private static function bracketOf(\DOMNode $c): ?\DOMElement
    {
        if (!$c instanceof \DOMElement) {
            return null;
        }
        if (in_array($c->localName, ['msup', 'msub', 'msubsup'], true)) {
            foreach ($c->childNodes as $first) {
                if ($first instanceof \DOMElement) {
                    $c = $first;
                    break;
                }
            }
        }
        return $c->localName === 'mo' && $c->getAttribute('fence') !== 'true' && in_array($c->textContent, ['(', ')', '[', ']'], true) ? $c : null;
    }

    /** Plain ( [ ) ] among $parent's children that have no partner of the same kind become symbols. */
    private static function demoteUnmatchedBrackets(\DOMDocument $dom, \DOMElement $parent): void
    {
        // scripts and fractions hold one operand per child; their brackets pair up one level higher
        if (in_array($parent->localName, ['msup', 'msub', 'msubsup', 'munder', 'mover', 'munderover', 'mfrac', 'mroot'], true)) {
            return;
        }
        $pairs = [')' => '(', ']' => '['];
        $stack = [];
        $unmatched = [];
        foreach (iterator_to_array($parent->childNodes) as $c) {
            $b = self::bracketOf($c);
            if ($b === null) {
                continue;
            }
            $t = $b->textContent;
            if ($t === '(' || $t === '[') {
                $stack[] = $b;
            } else {
                $top = end($stack);
                if ($top !== false && $top->textContent === $pairs[$t]) {
                    array_pop($stack);
                } else {
                    $unmatched[] = $b;
                }
            }
        }
        foreach (array_merge($stack, $unmatched) as $mo) {
            self::asSymbol($dom, $mo);
        }
    }
}
