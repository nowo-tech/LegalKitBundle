<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Versioning\Diff;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

use function in_array;
use function libxml_clear_errors;
use function libxml_use_internal_errors;
use function preg_replace;
use function rtrim;
use function str_repeat;
use function strtolower;
use function substr;
use function trim;

use const LIBXML_HTML_NODEFDTD;
use const LIBXML_HTML_NOIMPLIED;
use const LIBXML_NONET;

/**
 * Projects sanitized HTML to plain-text lines for the text diff: one line per block element, inline text joined,
 * links as "text (href)", images as "[alt]", list items prefixed "• ", headings "#"×level, table cells joined with
 * " | ". Tokens stay literal. The result is decoded text (an encoded "&lt;script&gt;" becomes "<script>"): it is
 * data, and the template must escape it.
 *
 * @internal
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class LegalTextProjector
{
    /** Elements that start and end a line. */
    public const array BLOCKS = ['p', 'div', 'section', 'article', 'header', 'footer', 'blockquote', 'pre', 'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'caption', 'figure', 'figcaption', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'address', 'details', 'summary'];

    private const string ROOT_ID = 'nowo-legal-kit-projection';

    /**
     * Plain-text lines of a sanitized HTML fragment.
     *
     * @return list<string>
     */
    public static function lines(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><div id="' . self::ROOT_ID . '">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $lines  = [];
        $buffer = '';
        $root   = $document->getElementById(self::ROOT_ID);
        if ($root instanceof DOMElement) {
            self::walk($root, $lines, $buffer);
        }
        self::flush($lines, $buffer);

        return $lines;
    }

    /**
     * @param list<string> $lines
     */
    private static function walk(DOMNode $node, array &$lines, string &$buffer): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $buffer .= $child->data;

                continue;
            }
            if (!$child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if ($tag === 'br') {
                self::flush($lines, $buffer);

                continue;
            }
            if ($tag === 'img') {
                $buffer .= '[' . $child->getAttribute('alt') . ']';

                continue;
            }
            if (!in_array($tag, self::BLOCKS, true)) {
                self::walk($child, $lines, $buffer);
                if ($tag === 'a' && $child->getAttribute('href') !== '') {
                    $buffer .= ' (' . $child->getAttribute('href') . ')';
                }
                if ($tag === 'td' || $tag === 'th') {
                    $buffer .= ' | ';
                }

                continue;
            }

            self::flush($lines, $buffer);
            if ($tag === 'li') {
                $buffer = '• ';
            } elseif ($tag[0] === 'h' && in_array(substr($tag, 1), ['1', '2', '3', '4', '5', '6'], true)) {
                $buffer = str_repeat('#', (int) substr($tag, 1)) . ' ';
            }
            self::walk($child, $lines, $buffer);
            self::flush($lines, $buffer);
        }
    }

    /**
     * @param list<string> $lines
     */
    private static function flush(array &$lines, string &$buffer): void
    {
        $line   = rtrim(trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $buffer) ?? $buffer), ' |');
        $buffer = '';
        if (trim($line, '#• ') !== '') {
            $lines[] = $line;
        }
    }
}
