<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Token;

use Throwable;
use Twig\Environment;
use Twig\TwigFunction;

use function is_array;
use function is_callable;
use function is_string;

/**
 * `%cookie_inventory%`: the CookieConsent cookie inventory of the request (`nowo_cookie_consent_cookie_inventory()`)
 * as a table, so the cookies page matches the consent modal. Without CookieConsent (or with an empty inventory) it
 * renders nothing.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class CookieInventoryTokenResolver implements LegalTokenResolverInterface
{
    public const string TEMPLATE = '@NowoLegalKitBundle/_cookie_inventory.html.twig';

    private const string INVENTORY_FUNCTION = 'nowo_cookie_consent_cookie_inventory';

    /**
     * @param Environment $twig Twig environment (CookieConsent registers the inventory function there)
     */
    public function __construct(
        private Environment $twig,
    ) {
    }

    public function names(): array
    {
        return ['cookie_inventory'];
    }

    public function resolve(string $name, LegalTokenContext $context): ?LegalTokenValue
    {
        if ($name !== 'cookie_inventory') {
            return null;
        }

        return LegalTokenValue::html($this->render());
    }

    /**
     * The inventory table HTML ('' without CookieConsent or without cookies).
     *
     * @return string The value
     */
    public function render(): string
    {
        try {
            $rows = $this->rows();
        } catch (Throwable) {
            // No request / no consent configuration (CLI, scanner): the token renders nothing.
            return '';
        }
        if ($rows === []) {
            return '';
        }

        return $this->twig->render(self::TEMPLATE, ['cookies' => $rows]);
    }

    /**
     * @return list<array{name: string, category: string, duration: string, purpose: string, provider: string}>
     */
    private function rows(): array
    {
        $function = $this->twig->getFunction(self::INVENTORY_FUNCTION);
        $callable = $function instanceof TwigFunction ? $function->getCallable() : null;
        if (!is_callable($callable)) {
            return [];
        }
        $inventory = $callable();
        if (!is_array($inventory)) {
            return [];
        }

        $rows = [];
        foreach ($inventory as $cookie) {
            if (!is_array($cookie) || !isset($cookie['name']) || !is_string($cookie['name'])) {
                continue;
            }
            $rows[] = [
                'name'     => $cookie['name'],
                'category' => $this->string($cookie['category'] ?? ''),
                'duration' => $this->string($cookie['duration'] ?? ''),
                'purpose'  => $this->string($cookie['purpose'] ?? ''),
                'provider' => $this->string($cookie['provider'] ?? ''),
            ];
        }

        return $rows;
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
