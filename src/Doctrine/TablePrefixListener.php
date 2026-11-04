<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Doctrine;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;

use function str_starts_with;

/**
 * Prepends `nowo_legal_kit.doctrine.table_prefix` to the bundle table and to its index / unique constraint names
 * (REQ-ORM-002). No-op with an empty prefix; host and third-party metadata is never touched.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class TablePrefixListener
{
    /** Namespaces of the bundle entities (the legal document, and the versioning / acceptance entities since 1.4). */
    private const array NAMESPACES = ['Nowo\\LegalKitBundle\\Entity\\', 'Nowo\\LegalKitBundle\\Versioning\\Entity\\', 'Nowo\\LegalKitBundle\\Acceptance\\Entity\\'];

    /**
     * @param string $tablePrefix Prefix of the bundle tables ('' = none)
     */
    public function __construct(
        private string $tablePrefix,
    ) {
    }

    /**
     * Rewrites the primary table, indexes and unique constraints of bundle entities.
     *
     * @return void Nothing (side effects only)
     */
    public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
    {
        if ($this->tablePrefix === '') {
            return;
        }

        $metadata = $event->getClassMetadata();
        $name     = $metadata->getName();
        $owned    = false;
        foreach (self::NAMESPACES as $namespace) {
            $owned = $owned || str_starts_with($name, $namespace);
        }
        if (!$owned) {
            return;
        }

        $table         = $metadata->table;
        $table['name'] = $this->tablePrefix . $metadata->getTableName();
        foreach (['indexes', 'uniqueConstraints'] as $kind) {
            if (!isset($table[$kind])) {
                continue;
            }
            $renamed = [];
            foreach ($table[$kind] as $name => $definition) {
                $renamed[$this->tablePrefix . $name] = $definition;
            }
            $table[$kind] = $renamed;
        }
        $metadata->setPrimaryTable($table);
    }
}
