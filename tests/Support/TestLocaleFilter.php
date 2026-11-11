<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Support;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;
use Nowo\LegalKitBundle\Entity\LegalDocument;

/**
 * A host SQL filter (tenant-like): legal documents of the `locale` parameter only; no parameter = no constraint.
 */
final class TestLocaleFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if ($targetEntity->getName() !== LegalDocument::class || !$this->hasParameter('locale')) {
            return '';
        }

        return $targetTableAlias . '.locale = ' . $this->getParameter('locale');
    }
}
