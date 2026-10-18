<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Sanitizer;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Nowo\Ckeditor5EditorBundle\Security\Ckeditor5HtmlSanitizerInterface;

use function array_merge;
use function array_unique;
use function array_values;
use function in_array;
use function libxml_clear_errors;
use function libxml_use_internal_errors;
use function preg_match;
use function str_starts_with;
use function strtolower;
use function trim;

/**
 * Canonical legal HTML sanitiser: optional CKEditor 5 strict pass, then a DOM allowlist (ported from
 * podologiapriego-web, plus `code`, `pre`, `mark`, `sup`, `sub`), then the {@see UrlPolicy}.
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

    /** @var array<string, list<string>> */
    private array $allowed;

    /**
     * @param UrlPolicy $urlPolicy URL rules of href / src
     * @param array<string, list<string>> $extraElements `sanitizer.allowed_elements` (merged; removed elements stay removed)
     * @param Ckeditor5HtmlSanitizerInterface|null $firstPass Optional CKEditor 5 strict sanitiser run first
     */
    public function __construct(
        private UrlPolicy $urlPolicy = new UrlPolicy(),
        array $extraElements = [],
        private ?Ckeditor5HtmlSanitizerInterface $firstPass = null,
    ) {
        $allowed = self::ALLOWED_ELEMENTS;
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

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="' . self::ROOT_ID . '">' . $html . '</div>',
            \LIBXML_HTML_NOIMPLIED | \LIBXML_HTML_NODEFDTD | \LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById(self::ROOT_ID);
        if (!$root instanceof DOMElement) {
            return ''; // @codeCoverageIgnore — the wrapper div is always parsed
        }
        // An unbalanced "</div>" closes the wrapper early: keep what follows instead of dropping it silently.
        while ($root->nextSibling instanceof DOMNode) {
            $root->appendChild($root->nextSibling);
        }
        $this->sanitizeChildren($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= (string) $document->saveHTML($child);
        }

        return trim($out);
    }

    private function sanitizeChildren(DOMNode $node): void
    {
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child instanceof DOMText) {
                continue; // text and CDATA content is escaped by saveHTML()
            }
            if (!$child instanceof DOMElement) {
                $node->removeChild($child); // comments, processing instructions

                continue;
            }

            $tag = strtolower($child->tagName);
            if (in_array($tag, self::REMOVED_ELEMENTS, true)) {
                $node->removeChild($child);

                continue;
            }
            if (!isset($this->allowed[$tag])) {
                $this->sanitizeChildren($child);
                while ($child->firstChild instanceof DOMNode) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);

                continue;
            }

            $this->sanitizeAttributes($child, $tag);
            $this->sanitizeChildren($child);
        }
    }

    private function sanitizeAttributes(DOMElement $element, string $tag): void
    {
        $allowed = $this->allowed[$tag];
        $remove  = [];
        foreach ($element->attributes as $attribute) {
            if (!$attribute instanceof DOMAttr) {
                continue; // @codeCoverageIgnore — DOMElement::attributes only yields DOMAttr
            }
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
            $element->setAttribute('rel', $this->safeRel($element->getAttribute('rel')));
        }
    }

    private function isSafeAttribute(string $tag, string $name, string $value): bool
    {
        return match ($name) {
            'href'               => $this->urlPolicy->isSafeLink($value),
            'src'                => $tag === 'img' && $this->urlPolicy->isSafeImage($value),
            'class'              => preg_match('/^[A-Za-z0-9_\- :]{0,200}$/', $value) === 1,
            'target'             => in_array($value, ['_blank', '_self'], true),
            'width', 'height'    => preg_match('/^\d{1,4}$/', $value) === 1,
            'colspan', 'rowspan' => preg_match('/^\d{1,3}$/', $value) === 1,
            'loading'            => in_array($value, ['lazy', 'eager'], true),
            'scope'              => in_array($value, ['row', 'col', 'rowgroup', 'colgroup'], true),
            'data-nowo-open-consent' => $value === '' || $value === 'true',
            default              => !str_starts_with($name, 'on') && !in_array($name, self::DENIED_ATTRIBUTES, true),
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
