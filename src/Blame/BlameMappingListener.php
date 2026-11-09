<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Blame;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Nowo\LegalKitBundle\DependencyInjection\Configuration;
use Nowo\LegalKitBundle\Entity\LegalDocument;
use Nowo\LegalKitBundle\Versioning\Entity\LegalPageVersion;

use function array_intersect;
use function in_array;

/**
 * Registered only when `nowo_legal_kit.doctrine.blame` is not `relation`: drops AuditKit's `createdBy` / `updatedBy`
 * ManyToOne (and their `created_by_id` / `updated_by_id` indexes) from the bundle entities, so the host user needs
 * neither `AuditUserInterface` nor `resolve_target_entities`; with `actor_id` it maps the string columns
 * `created_by` / `updated_by` instead.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class BlameMappingListener
{
    /** Entities carrying blame columns. */
    private const array ENTITIES = [LegalDocument::class, LegalPageVersion::class];

    /** Relation join columns removed outside the `relation` mode. */
    private const array JOIN_COLUMNS = ['created_by_id', 'updated_by_id'];

    /**
     * @param string $mode `doctrine.blame` (actor_id|none)
     */
    public function __construct(
        private string $mode,
    ) {
    }

    /**
     * Rewrites the blame mapping of the two bundle entities.
     *
     * @return void Nothing (side effects only)
     */
    public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
    {
        $metadata = $event->getClassMetadata();
        if ($this->mode === Configuration::BLAME_RELATION || !in_array($metadata->getName(), self::ENTITIES, true)) {
            return;
        }

        unset($metadata->associationMappings['createdBy'], $metadata->associationMappings['updatedBy']);
        $table = $metadata->table;
        foreach ($table['indexes'] ?? [] as $name => $index) {
            if (array_intersect($index['columns'] ?? [], self::JOIN_COLUMNS) !== []) {
                unset($table['indexes'][$name]);
            }
        }
        $metadata->setPrimaryTable($table);

        if ($this->mode !== Configuration::BLAME_ACTOR_ID) {
            return;
        }
        foreach (['createdByActorId' => 'created_by', 'updatedByActorId' => 'updated_by'] as $field => $column) {
            if (!$metadata->hasField($field)) {
                $metadata->mapField(['fieldName' => $field, 'columnName' => $column, 'type' => 'string', 'length' => LegalActorIdResolverInterface::MAX_LENGTH, 'nullable' => true]);
            }
        }
    }
}
