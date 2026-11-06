<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Sanitizer;

use function array_pop;
use function array_splice;
use function count;
use function end;
use function in_array;
use function max;
use function str_contains;
use function strcspn;
use function strlen;
use function strpos;
use function strspn;
use function strtolower;
use function substr;
use function substr_compare;
use function substr_count;

use const PHP_INT_MAX;

/**
 * Estimates the element nesting of raw HTML before any parser sees it (very deep text crashes the HTML5 parser of
 * PHP 8.4 in native code, where nothing can catch it).
 *
 * The tag scan follows the HTML tokenizer (comments, quoted attribute values, raw-text elements), and the stack follows
 * only those tree-building rules that are certain: an end tag removes an entry only when the parser would do the same,
 * and unclosed formatting elements stay counted because the parser reopens them. Whatever the scan cannot follow
 * exactly (CDATA in foreign content, raw-text tags where the parser may not treat them as raw) counts as too deep.
 *
 * Elements that are not counted (void, optional end tags, implied table parts) make real nesting up to about six times
 * the estimate on purpose-built text, which stays far below what crashes the parser. Malformed text can also be
 * estimated above its real nesting. So the limit is an estimate in both directions, not an exact element count.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class NestingEstimator
{
    private const string WHITESPACE = " \t\n\f\r";

    /**
     * How far an end tag looks down the stack. Keeps repeated end tags cheap on long text; beyond this an end tag closes
     * nothing here, which can only raise the estimate.
     */
    private const int MAX_WALK = 64;

    private const array VOID_OR_OPTIONAL_END = [
        'area', 'base', 'basefont', 'bgsound', 'br', 'col', 'embed', 'frame', 'hr', 'img', 'input', 'keygen', 'link', 'meta',
        'param', 'source', 'track', 'wbr', 'p', 'li', 'dt', 'dd', 'tr', 'td', 'th', 'tbody', 'thead', 'tfoot', 'option',
        'optgroup', 'colgroup',
    ];

    private const array RAW_TEXT = ['script', 'style', 'xmp', 'iframe', 'noembed', 'noframes', 'textarea', 'title', 'plaintext'];

    private const array FORMATTING = ['a', 'b', 'big', 'code', 'em', 'font', 'i', 'nobr', 's', 'small', 'strike', 'strong', 'tt', 'u'];

    private const array HEADINGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    /** End tags that close every open element up to their match, unless a scope barrier is in between. */
    private const array BLOCK_CLOSERS = [
        'address', 'article', 'aside', 'blockquote', 'center', 'details', 'dialog', 'dir', 'div', 'dl', 'fieldset', 'figcaption',
        'figure', 'footer', 'header', 'hgroup', 'listing', 'main', 'menu', 'nav', 'ol', 'pre', 'search', 'section', 'summary', 'ul',
    ];

    private const array SCOPE_BARRIERS = ['applet', 'caption', 'html', 'marquee', 'noscript', 'object', 'select', 'table', 'template'];

    /** Start tags that leave foreign content, or start HTML inside it: self-closing no longer applies after one. */
    private const array LEAVES_FOREIGN = [
        'b', 'big', 'blockquote', 'body', 'br', 'center', 'code', 'dd', 'div', 'dl', 'dt', 'em', 'embed', 'font', 'h1', 'h2', 'h3',
        'h4', 'h5', 'h6', 'head', 'hr', 'i', 'img', 'li', 'listing', 'menu', 'meta', 'nobr', 'ol', 'p', 'pre', 'ruby', 's', 'small',
        'span', 'strong', 'strike', 'sub', 'sup', 'table', 'tt', 'u', 'ul', 'var',
        'foreignobject', 'desc', 'title', 'mi', 'mo', 'mn', 'ms', 'mtext', 'annotation-xml',
    ];

    /**
     * Whether the estimated nesting of a text is above the limit.
     *
     * @return bool The answer
     */
    public static function exceeds(string $html, int $limit): bool
    {
        return substr_count($html, '<') > $limit && self::peak($html, $limit) > $limit;
    }

    /**
     * The estimated nesting, `PHP_INT_MAX` for text the scan cannot follow.
     *
     * @param int $stopAbove Stops scanning once the estimate is above this value
     *
     * @return int The estimate
     */
    public static function peak(string $html, int $stopAbove = PHP_INT_MAX): int
    {
        $html     = strtolower($html);
        $length   = strlen($html);
        $stack    = [];
        $peak     = 0;
        $foreign  = 0;
        $selfOk   = true;
        $position = 0;

        while (($position = strpos($html, '<', $position)) !== false) {
            $next = $html[$position + 1] ?? '';
            if ($next === '!') {
                if ($foreign > 0 && substr_compare($html, '[cdata[', $position + 2, 7) === 0) {
                    return PHP_INT_MAX;
                }
                $position = self::afterMarkupDeclaration($html, $position);
            } elseif ($next === '?') {
                $position = self::afterBogusComment($html, $position + 2);
            } elseif ($next === '/') {
                $first = $html[$position + 2] ?? '';
                if (!self::isAsciiLetter($first)) {
                    $position = $first === '>' ? $position + 3 : self::afterBogusComment($html, $position + 2);
                } else {
                    $nameEnd    = $position + 2 + strcspn($html, self::WHITESPACE . '/>', $position + 2);
                    $name       = substr($html, $position + 2, $nameEnd - $position - 2);
                    [$position] = self::afterAttributes($html, $nameEnd, $length);
                    if ($position !== null) {
                        self::close($stack, $name, $foreign);
                    }
                }
            } elseif (!self::isAsciiLetter($next)) {
                ++$position;
            } else {
                $nameEnd              = $position + 1 + strcspn($html, self::WHITESPACE . '/>', $position + 1);
                $name                 = substr($html, $position + 1, $nameEnd - $position - 1);
                [$position, $selfEnd] = self::afterAttributes($html, $nameEnd, $length);
                if ($position === null) {
                    break; // End of file inside a tag: the tag is dropped and nothing follows.
                }

                if (in_array($name, self::RAW_TEXT, true) && ($foreign === 0 || !$selfOk)) {
                    if ($foreign > 0 || in_array('select', $stack, true)) {
                        return PHP_INT_MAX; // The parser may or may not treat this tag as raw text.
                    }
                    if ($name === 'plaintext') {
                        break;
                    }
                    $close = self::rawTextEnd($html, $name, $position);
                    if ($close === null) {
                        break;
                    }
                    if ($name === 'script' && str_contains(substr($html, $position, $close - $position), '<!--')) {
                        return PHP_INT_MAX; // Script data can hide its own end tag behind "<!--".
                    }
                    $position = $close;

                    continue;
                }
                if ($foreign === 0) {
                    if (in_array($name, self::VOID_OR_OPTIONAL_END, true)) {
                        continue;
                    }
                    $top = end($stack);
                    if ($top === $name && ($name === 'a' || $name === 'nobr')) {
                        continue; // A second one closes the first.
                    }
                    if (in_array($name, self::HEADINGS, true) && in_array($top, self::HEADINGS, true)) {
                        continue; // A heading closes an open heading.
                    }
                } elseif (in_array($name, self::LEAVES_FOREIGN, true)) {
                    $selfOk = false;
                }
                $isForeignRoot = $name === 'svg' || $name === 'math';
                if ($selfEnd && $selfOk && ($foreign > 0 || $isForeignRoot)) {
                    continue;
                }
                $stack[] = $name;
                if ($isForeignRoot) {
                    ++$foreign;
                }
                $depth = count($stack);
                if ($depth > $peak) {
                    $peak = $depth;
                    if ($peak > $stopAbove) {
                        return $peak;
                    }
                }
            }
            if ($position === null) {
                break;
            }
        }

        return $peak;
    }

    /**
     * Removes what an end tag certainly closes.
     *
     * @param list<string> $stack Open elements
     * @param int $foreign Open `svg` and `math` elements
     */
    private static function close(array &$stack, string $name, int &$foreign): void
    {
        $heading = in_array($name, self::HEADINGS, true);
        if (!$heading && end($stack) === $name) {
            array_pop($stack);
            if ($name === 'svg' || $name === 'math') {
                --$foreign;
            }

            return;
        }
        if ($foreign > 0) {
            return;
        }
        if (in_array($name, self::FORMATTING, true)) {
            $bottom = max(0, count($stack) - self::MAX_WALK);
            for ($i = count($stack) - 1; $i >= $bottom && in_array($stack[$i], self::FORMATTING, true); --$i) {
                if ($stack[$i] === $name) {
                    array_splice($stack, $i, 1);

                    return;
                }
            }

            return;
        }
        if (!$heading && !in_array($name, self::BLOCK_CLOSERS, true)) {
            return;
        }
        $bottom = max(0, count($stack) - self::MAX_WALK);
        for ($i = count($stack) - 1; $i >= $bottom; --$i) {
            if ($heading ? in_array($stack[$i], self::HEADINGS, true) : $stack[$i] === $name) {
                $kept = [];
                foreach (array_splice($stack, $i) as $entry) {
                    if (in_array($entry, self::FORMATTING, true)) {
                        $kept[] = $entry; // The parser reopens unclosed formatting elements later.
                    }
                }
                foreach ($kept as $entry) {
                    $stack[] = $entry;
                }

                return;
            }
            if (in_array($stack[$i], self::SCOPE_BARRIERS, true)) {
                return;
            }
        }
    }

    /**
     * Position after `<!…`: a comment with the tokenizer's rules, or a bogus comment up to the first `>`.
     *
     * @return ?int The position (null: the text ends inside it)
     */
    private static function afterMarkupDeclaration(string $html, int $position): ?int
    {
        if (substr_compare($html, '--', $position + 2, 2) !== 0) {
            return self::afterBogusComment($html, $position + 2);
        }
        $start = $position + 4;
        if (($html[$start] ?? '') === '>') {
            return $start + 1;
        }
        if (($html[$start] ?? '') === '-' && ($html[$start + 1] ?? '') === '>') {
            return $start + 2;
        }
        // One forward walk: searching "-->" and "--!>" separately would rescan the text for every comment.
        while (($dashes = strpos($html, '--', $start)) !== false) {
            $after = $html[$dashes + 2] ?? '';
            if ($after === '>') {
                return $dashes + 3;
            }
            if ($after === '!' && ($html[$dashes + 3] ?? '') === '>') {
                return $dashes + 4;
            }
            $start = $dashes + 1;
        }

        return null;
    }

    private static function afterBogusComment(string $html, int $position): ?int
    {
        $end = strpos($html, '>', $position);

        return $end === false ? null : $end + 1;
    }

    /**
     * Walks the attributes of a tag as the tokenizer does.
     *
     * @return array{0: ?int, 1: bool} Position after the tag (null: the text ends inside it) and whether it ends in `/>`
     */
    private static function afterAttributes(string $html, int $position, int $length): array
    {
        while (true) {
            $position += strspn($html, self::WHITESPACE, $position);
            if ($position >= $length) {
                return [null, false];
            }
            $char = $html[$position];
            if ($char === '>') {
                return [$position + 1, false];
            }
            if ($char === '/') {
                if (($html[$position + 1] ?? '') === '>') {
                    return [$position + 2, true];
                }
                ++$position;

                continue;
            }
            // The first character of a name may be "=".
            $position += 1 + strcspn($html, self::WHITESPACE . '/>=', $position + 1);
            $position += strspn($html, self::WHITESPACE, $position);
            if (($html[$position] ?? '') !== '=') {
                continue;
            }
            ++$position;
            $position += strspn($html, self::WHITESPACE, $position);
            $quote = $html[$position] ?? '';
            if ($quote === '"' || $quote === "'") {
                $end = strpos($html, $quote, $position + 1);
                if ($end === false) {
                    return [null, false];
                }
                $position = $end + 1;
            } elseif ($quote === '>') {
                return [$position + 1, false];
            } else {
                $position += strcspn($html, self::WHITESPACE . '>', $position);
            }
        }
    }

    /**
     * Position of the end tag of a raw-text element (null: the text ends first).
     */
    private static function rawTextEnd(string $html, string $name, int $position): ?int
    {
        $needle = '</' . $name;
        while (($found = strpos($html, $needle, $position)) !== false) {
            $after = $html[$found + strlen($needle)] ?? '';
            if ($after !== '' && strspn($after, self::WHITESPACE . '/>') === 1) {
                return $found;
            }
            $position = $found + 1;
        }

        return null;
    }

    private static function isAsciiLetter(string $char): bool
    {
        return $char !== '' && $char >= 'a' && $char <= 'z';
    }
}
