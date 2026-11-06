<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Versioning;

use Nowo\LegalKitBundle\Catalog\LegalPageCatalogInterface;
use Nowo\LegalKitBundle\DependencyInjection\Compiler\IntegrationPass;
use Nowo\LegalKitBundle\Sanitizer\DomAllowlistSanitizer;
use Nowo\LegalKitBundle\Sanitizer\LegalHtmlSanitizerInterface;

use function hash;
use function html_entity_decode;
use function htmlspecialchars;
use function in_array;
use function is_array;
use function parse_url;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function preg_replace_callback;
use function rtrim;
use function sprintf;
use function str_replace;
use function strpos;
use function strtolower;
use function substr;
use function trim;

use const ENT_HTML5;
use const ENT_QUOTES;

/**
 * Normalisation and hash of a legal text (`legalkit-seed-v1`): the body is sanitized with the current sanitizer
 * (tokens kept, never rendered), links to a LegalKit public page are rewritten to the canonical `%legal_link:<slug>%`
 * form (with or without a `/{locale}` prefix, relative or absolute on the configured host), line endings unified,
 * whitespace runs (NBSP included) collapsed and whitespace between tags removed. Two texts with the same hash count
 * as the same text; any other difference counts as an edit.
 *
 * The link rewrite exists because a seed render prints the page links of the instance that rendered it (the default
 * locale is unprefixed, the others are prefixed): a snapshot taken under one default locale must match an unedited
 * copy stored under another one. The canonical form exists only inside the hash; nothing is ever rendered from it.
 *
 * @internal
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class LegalSeedHasher
{
    /** Prefix of every hash: a later normalisation becomes v2 without silently changing old comparisons. */
    public const string VERSION = 'legalkit-seed-v1';

    /** Canonical href of a LegalKit public page inside the normalised text. */
    public const string LINK_FORM = '%%legal_link:%s%%';

    /**
     * @param LegalHtmlSanitizerInterface $sanitizer The bundle sanitizer
     * @param ?LegalPageCatalogInterface $catalog Enabled pages (null = no link rewrite)
     * @param string $pathPrefix `routing.path_prefix`
     * @param ?string $host Host whose absolute links count as internal (router request context); null = relative only
     */
    public function __construct(
        private LegalHtmlSanitizerInterface $sanitizer,
        private ?LegalPageCatalogInterface $catalog = null,
        private string $pathPrefix = '/legal',
        private ?string $host = null,
    ) {
    }

    /**
     * Hash of a title and an HTML body.
     *
     * @return string 64 hexadecimal characters
     */
    public function hash(string $title, string $body): string
    {
        return hash('sha256', self::VERSION . "\n" . self::normalizeText($title) . "\n" . $this->normalizeBody($body));
    }

    /**
     * Hash of the same text as printed with `sanitizer.heading_anchors` empty. Only for comparing with a hash recorded
     * before the option was turned on: an unchanged text must not count as an edit or as an unknown snapshot.
     *
     * @return string 64 hexadecimal characters
     */
    public function hashWithoutAnchorIds(string $title, string $body): string
    {
        return hash('sha256', self::VERSION . "\n" . self::normalizeText($title) . "\n" . DomAllowlistSanitizer::withoutAnchorIds($this->normalizeBody($body)));
    }

    /**
     * Sanitized, link-canonical, whitespace-normalised body.
     *
     * @return string The normalised body
     */
    public function normalizeBody(string $html): string
    {
        $html = str_replace(["\r\n", "\r", '&nbsp;', '&#160;', '&#xA0;', '&#xa0;'], ["\n", "\n", ' ', ' ', ' ', ' '], $this->sanitizer->sanitize($html));
        $html = $this->canonicalLinks(self::collapse($html));

        return trim(preg_replace('/>\s+</', '><', $html) ?? $html);
    }

    /**
     * Trimmed text with whitespace runs (NBSP included) collapsed to one space.
     *
     * @return string The normalised text
     */
    public static function normalizeText(string $text): string
    {
        return trim(self::collapse($text));
    }

    /**
     * The slug of the LegalKit public page an href points to (null = any other target).
     *
     * @return ?string The slug
     */
    public function linkedSlug(string $href): ?string
    {
        if (!$this->catalog instanceof LegalPageCatalogInterface) {
            return null;
        }
        $parts = parse_url(trim($href));
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query'])) {
            return null;
        }
        if (isset($parts['scheme']) || isset($parts['host'])) {
            $scheme = strtolower($parts['scheme'] ?? '');
            if ($this->host === null || !isset($parts['host']) || !in_array($scheme, ['http', 'https', ''], true) || strtolower($parts['host']) !== strtolower($this->host)) {
                return null;
            }
        }
        $path   = rtrim($parts['path'] ?? '', '/');
        $prefix = rtrim($this->pathPrefix, '/');
        foreach ($this->catalog->pages() as $page) {
            $pattern = '#^(?:/' . IntegrationPass::GENERIC_LOCALE_REQUIREMENT . ')?' . preg_quote($prefix . '/' . $page->path, '#') . '$#';
            if (preg_match($pattern, $path) === 1) {
                return $page->slug;
            }
        }

        return null;
    }

    /**
     * Rewrites `href="…"` values that point to a LegalKit page (the fragment is kept).
     */
    private function canonicalLinks(string $html): string
    {
        if (!$this->catalog instanceof LegalPageCatalogInterface) {
            return $html;
        }

        return preg_replace_callback('/\bhref="([^"]*)"/', function (array $match): string {
            $href     = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $fragment = '';
            $hash     = strpos($href, '#');
            if ($hash !== false) {
                $fragment = substr($href, $hash);
                $href     = substr($href, 0, $hash);
            }
            $slug = $href === '' ? null : $this->linkedSlug($href);
            if ($slug === null) {
                return $match[0];
            }

            return 'href="' . htmlspecialchars(sprintf(self::LINK_FORM, $slug) . $fragment, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"';
        }, $html) ?? $html;
    }

    private static function collapse(string $text): string
    {
        // Invalid UTF-8 cannot use the /u class: fall back to ASCII whitespace instead of hashing an empty string.
        return preg_replace('/[\s\x{00A0}]+/u', ' ', $text) ?? preg_replace('/\s+/', ' ', $text) ?? $text;
    }
}
