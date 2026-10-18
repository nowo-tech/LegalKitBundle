<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Token;

use function array_keys;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function htmlspecialchars;
use function is_string;
use function preg_match_all;
use function preg_replace_callback;

use const ENT_HTML5;
use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

/**
 * Replaces `%token%` placeholders with the values of the resolver chain (first non-null answer wins). Plain-text
 * values are HTML-escaped; unknown tokens are left as they are (and reported by the placeholder scanner).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class LegalTokenRenderer
{
    /** A token: `%` + lowercase name + `%`. */
    public const string TOKEN_PATTERN = '/%([a-z][a-z0-9_]{0,63})%/';

    /** @var list<LegalTokenResolverInterface> */
    private array $resolvers;

    /**
     * @param iterable<LegalTokenResolverInterface> $resolvers Tagged resolvers, highest priority first
     */
    public function __construct(iterable $resolvers)
    {
        $list = [];
        foreach ($resolvers as $resolver) {
            $list[] = $resolver;
        }
        $this->resolvers = $list;
    }

    /**
     * Resolves the tokens of an HTML fragment.
     *
     * @return string The value
     */
    public function renderHtml(string $html, LegalTokenContext $context): string
    {
        return (string) preg_replace_callback(self::TOKEN_PATTERN, function (array $match) use ($context): string {
            $value = $this->value($match[1], $context);
            if (!$value instanceof LegalTokenValue) {
                return $match[0];
            }

            return $value->html ? $value->value : htmlspecialchars($value->value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        }, $html);
    }

    /**
     * Resolves the tokens of a plain-text string (titles); HTML values contribute nothing.
     *
     * @return string The value
     */
    public function renderText(string $text, LegalTokenContext $context): string
    {
        return (string) preg_replace_callback(self::TOKEN_PATTERN, function (array $match) use ($context): string {
            $value = $this->value($match[1], $context);
            if (!$value instanceof LegalTokenValue) {
                return $match[0];
            }

            return $value->html ? '' : $value->value;
        }, $text);
    }

    /**
     * Every token name known by the chain.
     *
     * @return list<string>
     */
    public function knownNames(): array
    {
        $names = [];
        foreach ($this->resolvers as $resolver) {
            $names = array_merge($names, $resolver->names());
        }

        return array_values(array_unique($names));
    }

    /**
     * Token names used in a text that no resolver resolves in this context (unknown, or known without a value such as
     * `%brand%` while `brand_name` is empty).
     *
     * @return list<string>
     */
    public function unresolvedNames(string $text, LegalTokenContext $context): array
    {
        if (preg_match_all(self::TOKEN_PATTERN, $text, $matches) === 0) {
            return [];
        }
        $unresolved = [];
        foreach ($matches[1] as $name) {
            if (!isset($unresolved[$name]) && !$this->value($name, $context) instanceof LegalTokenValue) {
                $unresolved[$name] = true;
            }
        }

        return array_map(strval(...), array_keys($unresolved));
    }

    private function value(string $name, LegalTokenContext $context): ?LegalTokenValue
    {
        foreach ($this->resolvers as $resolver) {
            $value = $resolver->resolve($name, $context);
            if ($value !== null) {
                return is_string($value) ? LegalTokenValue::text($value) : $value;
            }
        }

        return null;
    }
}
