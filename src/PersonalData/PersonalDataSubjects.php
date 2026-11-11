<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\PersonalData;

use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceManager;

use function array_keys;
use function array_map;

/**
 * The distinct, storable subject ids of a resolver result, in order (internal helper of the UserKit adapters).
 *
 * @internal
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class PersonalDataSubjects
{
    /**
     * @param list<string> $subjectIds Resolver result
     *
     * @return list<string> Distinct ids that fit the column (exact comparison: no trimming, no case folding)
     */
    public static function distinct(array $subjectIds): array
    {
        $distinct = [];
        foreach ($subjectIds as $subjectId) {
            if (LegalAcceptanceManager::isStorableSubjectId($subjectId)) {
                $distinct[$subjectId] = true;
            }
        }

        // Numeric-string keys come back as integers.
        return array_map(static fn (int|string $id): string => (string) $id, array_keys($distinct));
    }
}
