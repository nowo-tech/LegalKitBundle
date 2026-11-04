<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Doctrine;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Nowo\LegalKitBundle\Entity\LegalDocument;

/**
 * Maps `legal_document.seed_revision` / `seed_hash` (nullable) when `versioning.enabled` is true. Without it the
 * entity keeps the 1.3 mapping, so adopters that do not opt in see no schema change.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class VersioningMappingListener
{
    /**
     * Adds the two seed columns to the LegalDocument metadata.
     *
     * @return void Nothing (side effects only)
     */
    public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
    {
        $metadata = $event->getClassMetadata();
        if ($metadata->getName() !== LegalDocument::class) {
            return;
        }
        if (!$metadata->hasField('seedRevision')) {
            $metadata->mapField(['fieldName' => 'seedRevision', 'columnName' => 'seed_revision', 'type' => 'string', 'length' => 32, 'nullable' => true]);
        }
        if (!$metadata->hasField('seedHash')) {
            $metadata->mapField(['fieldName' => 'seedHash', 'columnName' => 'seed_hash', 'type' => 'string', 'length' => 64, 'nullable' => true, 'options' => ['fixed' => true]]);
        }
    }
}
