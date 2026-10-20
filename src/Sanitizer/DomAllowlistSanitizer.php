<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Sanitizer;

use Dom\Attr;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;
use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Nowo\Ckeditor5EditorBundle\Security\Ckeditor5HtmlSanitizerInterface;

use function array_merge;
use function array_unique;
use function array_values;
use function assert;
use function class_exists;
use function in_array;
use function libxml_clear_errors;
use function libxml_use_internal_errors;
use function preg_match;
use function str_starts_with;
use function strtolower;
use function trim;

use const LIBXML_HTML_NODEFDTD;
use const LIBXML_HTML_NOIMPLIED;
use const LIBXML_NONET;

/**
 * Canonical legal HTML sanitiser: optional CKEditor 5 strict pass, then a DOM allowlist (ported from
 * podologiapriego-web, plus `code`, `pre`, `mark`, `sup`, `sub`), then the {@see UrlPolicy}.
 *
 * On PHP 8.4+ the HTML is parsed with the HTML5 algorithm browsers use (`Dom\HTMLDocument`, fragment parsing in a
 * `div` context) and always re-serialised, so the output means the same to the browser as to the allowlist
 * (REQ-PBK-009: no parser-differential / mutation XSS); elements outside the HTML namespace are removed. PHP 8.3
 * falls back to libxml's HTML 4 parser (`DOMDocument`) with the same allowlist.
 *
 * Unknown elements are unwrapped (their text stays); script-like and embedding elements are removed with their
 * content; comments are dropped; only allowlisted attributes survive (`class` is the only presentational one, never
 * `style`); `target="_blank"` links get `rel="noopener noreferrer"`. Stateless (FrankenPHP worker safe).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class DomAllowlistSanitizer implements LegalHtmlSanitizerInterface
{
    /** @var array<string, list<string>> Element => allowed attributes */
    public const array ALLOWED_ELEMENTS = [
        'p'          => ['class'],
        'br'         => [],
        'strong'     => ['class'],
        'b'          => ['class'],
        'em'         => ['class'],
        'i'          => ['class'],
        'u'          => ['class'],
        's'          => ['class'],
        'h1'         => ['class'],
        'h2'         => ['class'],
        'h3'         => ['class'],
        'h4'         => ['class'],
        'ul'         => ['class'],
        'ol'         => ['class'],
        'li'         => ['class'],
        'blockquote' => ['class'],
        'a'          => ['href', 'title', 'rel', 'class', 'target', 'data-nowo-open-consent'],
        'img'        => ['src', 'alt', 'title', 'width', 'height', 'loading', 'class'],
        'table'      => ['class'],
        'thead'      => ['class'],
        'tbody'      => ['class'],
        'tr'         => ['class'],
        'th'         => ['class', 'colspan', 'rowspan', 'scope'],
        'td'         => ['class', 'colspan', 'rowspan'],
        'hr'         => ['class'],
        'span'       => ['class'],
        'div'        => ['class'],
        'section'    => ['class'],
        'article'    => ['class'],
        'header'     => ['class'],
        'footer'     => ['class'],
        'address'    => ['class'],
        'figure'     => ['class'],
        'figcaption' => ['class'],
        'code'       => ['class'],
        'pre'        => ['class'],
        'mark'       => ['class'],
        'sup'        => ['class'],
        'sub'        => ['class'],
    ];

    /** Elements removed together with their content (never unwrapped). */
    public const array REMOVED_ELEMENTS = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input', 'button',
        'select', 'option', 'textarea', 'svg', 'math', 'template', 'noscript', 'noembed', 'noframes', 'link', 'meta',
        'base', 'title', 'head', 'xmp', 'plaintext', 'portal', 'audio', 'video', 'source', 'track', 'canvas', 'dialog',
    ];

    /** Attributes never allowed, even through `sanitizer.allowed_elements` (URL-bearing, clobbering, styling). */
    public const array DENIED_ATTRIBUTES = [
        'style', 'srcdoc', 'srcset', 'formaction', 'action', 'xlink:href', 'ping', 'background', 'poster', 'data',
        'id', 'name', 'is', 'cite', 'longdesc', 'usemap', 'lowsrc', 'dynsrc',
    ];

    private const string ROOT_ID = 'nowo-legal-kit-root';

    private const string HTML_NAMESPACE = 'http://www.w3.org/1999/xhtml';

    /** @var array<string, list<string>> */
    private array $allowed;

    /** Whether the HTML5 parser is used (PHP 8.4+, unless disabled). */
    public bool $html5;

    /**
     * @param UrlPolicy $urlPolicy URL rules of href / src
     * @param array<string, list<string>> $extraElements `sanitizer.allowed_elements` (merged; removed elements stay removed)
     * @param Ckeditor5HtmlSanitizerInterface|null $firstPass Optional CKEditor 5 strict sanitiser run first
     * @param bool|null $html5 HTML5 parser (`Dom\HTMLDocument`); null: whenever PHP provides it (8.4+)
     */
    public function __construct(
        private UrlPolicy $urlPolicy = new UrlPolicy(),
        array $extraElements = [],
        private ?Ckeditor5HtmlSanitizerInterface $firstPass = null,
        ?bool $html5 = null,
    ) {
        $this->html5 = ($html5 ?? true) && class_exists(HTMLDocument::class);
        $allowed     = self::ALLOWED_ELEMENTS;
        foreach ($extraElements as $element => $attributes) {
            $element = strtolower((string) $element);
            if (in_array($element, self::REMOVED_ELEMENTS, true)) {
                continue;
            }
            $safe = [];
            foreach ($attributes as $attribute) {
                $attribute = strtolower($attribute);
                if (!str_starts_with($attribute, 'on') && !in_array($attribute, self::DENIED_ATTRIBUTES, true)) {
                    $safe[] = $attribute;
                }
            }
            $allowed[$element] = array_values(array_unique(array_merge($allowed[$element] ?? [], $safe)));
        }
        $this->allowed = $allowed;
    }

    public function sanitize(string $html): string
    {
        if ($this->firstPass instanceof Ckeditor5HtmlSanitizerInterface) {
            $html = $this->firstPass->sanitize($html);
        }
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        return $this->html5 ? $this->sanitizeHtml5($html) : $this->sanitizeLegacy($html);
    }

    /**
     * HTML5 fragment parsing (the browser algorithm) in a `div` context, then the HTML5 serialiser.
     */
    private function sanitizeHtml5(string $html): string
    {
        $document = HTMLDocument::createEmpty();
        $root     = $document->createElement('div');
        $document->appendChild($root);
        // A stray "</div>" cannot close the context element: fragment parsing ignores it.
        $root->innerHTML = $html;
        $this->sanitizeChildren($root);

        return trim($root->innerHTML);
    }

    /**
     * libxml HTML 4 parser (PHP 8.3).
     */
    private function sanitizeLegacy(string $html): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="' . self::ROOT_ID . '">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById(self::ROOT_ID);
        assert($root instanceof DOMElement, 'The wrapper div is always parsed.');
        // An unbalanced "</div>" closes the wrapper early: keep what follows instead of dropping it silently.
        while ($root->nextSibling instanceof DOMNode) {
            // @igor-ignore - Local DOMDocument built per call, not shared state
            $root->appendChild($root->nextSibling);
        }
        $this->sanitizeChildren($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= (string) $document->saveHTML($child);
        }

        return trim($out);
    }

    private function sanitizeChildren(DOMNode|Node $node): void
    {
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child instanceof DOMText || $child instanceof Text) {
                continue; // text and CDATA content is escaped by the serialiser
            }
            if (!$child instanceof DOMElement && !$child instanceof Element) {
                $node->removeChild($child); // comments, processing instructions

                continue;
            }

            // HTML5 parser: foreign (SVG / MathML) content never reaches the output.
            $foreign = $child instanceof Element && $child->namespaceURI !== self::HTML_NAMESPACE;
            $tag     = strtolower($child instanceof Element ? $child->localName : $child->tagName);
            if ($foreign || in_array($tag, self::REMOVED_ELEMENTS, true)) {
                $node->removeChild($child);

                continue;
            }
            if (!isset($this->allowed[$tag])) {
                $this->sanitizeChildren($child);
                while ($child->firstChild instanceof DOMNode || $child->firstChild instanceof Node) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);

                continue;
            }

            $this->sanitizeAttributes($child, $tag);
            $this->sanitizeChildren($child);
        }
    }

    private function sanitizeAttributes(DOMElement|Element $element, string $tag): void
    {
        $allowed = $this->allowed[$tag];
        $remove  = [];
        /** @var Attr|DOMAttr $attribute The attributes map only yields attribute nodes */
        foreach ($element->attributes as $attribute) {
            $name = strtolower($attribute->name);
            if (!in_array($name, $allowed, true) || !$this->isSafeAttribute($tag, $name, $attribute->value)) {
                $remove[] = $attribute->name;
            }
        }
        foreach ($remove as $name) {
            $element->removeAttribute($name);
        }

        if ($tag === 'a' && $element->getAttribute('target') === '_blank') {
            $element->setAttribute('rel', 'noopener noreferrer');
        } elseif ($tag === 'a' && $element->hasAttribute('rel')) {
            $element->setAttribute('rel', $this->safeRel((string) $element->getAttribute('rel')));
        }
    }

    private function isSafeAttribute(string $tag, string $name, string $value): bool
    {
        return match ($name) {
            'href'                   => $this->urlPolicy->isSafeLink($value),
            'src'                    => $tag === 'img' && $this->urlPolicy->isSafeImage($value),
            'class'                  => preg_match('/^[A-Za-z0-9_\- :]{0,200}$/', $value) === 1,
            'target'                 => in_array($value, ['_blank', '_self'], true),
            'width', 'height'        => preg_match('/^\d{1,4}$/', $value) === 1,
            'colspan', 'rowspan'     => preg_match('/^\d{1,3}$/', $value) === 1,
            'loading'                => in_array($value, ['lazy', 'eager'], true),
            'scope'                  => in_array($value, ['row', 'col', 'rowgroup', 'colgroup'], true),
            'data-nowo-open-consent' => $value === '' || $value === 'true',
            default                  => !str_starts_with($name, 'on') && !in_array($name, self::DENIED_ATTRIBUTES, true),
        };
    }

    private function safeRel(string $rel): string
    {
        $tokens = [];
        foreach (preg_split('/\s+/', strtolower(trim($rel))) ?: [] as $token) {
            if (in_array($token, ['nofollow', 'noopener', 'noreferrer', 'external', 'license', 'author', 'help'], true)) {
                $tokens[] = $token;
            }
        }

        return implode(' ', array_values(array_unique($tokens)));
    }
}
