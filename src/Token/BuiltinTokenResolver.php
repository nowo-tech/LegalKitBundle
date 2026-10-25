<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Token;

use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Built-in tokens: `%brand%` (`nowo_legal_kit.brand_name`; unresolved — so reported — while empty) and
 * `%last_updated%` (row update date, else `seed.date`).
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
     */
    public function __construct(
        private string $brandName,
        private string $seedDate,
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
            'brand'        => $this->brandName !== '' ? $this->brandName : null,
            'last_updated' => $context->updatedAt instanceof DateTimeInterface
                ? DateTimeImmutable::createFromInterface($context->updatedAt)->format('Y-m-d')
                : $this->seedDate,
            default => null,
        };
    }
}
