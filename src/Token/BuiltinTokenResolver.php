<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Token;

use DateTimeImmutable;
use DateTimeInterface;
use Nowo\LegalKitBundle\Brand\LegalBrand;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Built-in tokens: `%brand%` and `%last_updated%` (row update date, else `seed.date`).
 *
 * `%brand%` is the name of the host's brand provider (`nowo-tech/brand-contracts`, spec 220 S5) when there is one and
 * `brand.mode` is `auto`; otherwise `nowo_legal_kit.brand_name`; unresolved (so reported by the gate) while both are
 * missing. A context {@see LegalTokenContext::$brandName} (a past name, for `verify-acceptance-hash`) wins over both.
 * The value is plain text: {@see LegalTokenRenderer} escapes it in HTML.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[AsTaggedItem(priority: -100)]
final readonly class BuiltinTokenResolver implements LegalTokenResolverInterface
{
    /**
     * @param string $brandName Value of %brand%
     * @param string $seedDate Y-m-d date of the shipped seed
     * @param LegalBrand|null $brand Brand provider consumer (null with `brand.mode: off` or without brand-contracts)
     */
    public function __construct(
        private string $brandName,
        private string $seedDate,
        private ?LegalBrand $brand = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function names(): array
    {
        return ['brand', 'last_updated'];
    }

    /**
     * {@inheritDoc}
     */
    public function resolve(string $name, LegalTokenContext $context): ?string
    {
        return match ($name) {
            'brand'        => $context->brandName ?? $this->brand?->name() ?? ($this->brandName !== '' ? $this->brandName : null),
            'last_updated' => $context->updatedAt instanceof DateTimeInterface
                ? DateTimeImmutable::createFromInterface($context->updatedAt)->format('Y-m-d')
                : $this->seedDate,
            default => null,
        };
    }
}
