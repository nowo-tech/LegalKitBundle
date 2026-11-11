<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Support;

use Nowo\LegalKitBundle\PersonalData\LegalPersonalDataSubjectResolverInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function str_repeat;

/**
 * Host subject SPI of the test kernel (`subject_spi`): the current id and an old one ("old-<identifier>", from the
 * original identifier when UserKit passes it), duplicates and ids that can never be stored (dropped by the adapters).
 */
final class TestPersonalDataSubjectResolver implements LegalPersonalDataSubjectResolverInterface
{
    public function subjectsFor(object $user, ?string $originalIdentifier): array
    {
        if (!$user instanceof UserInterface) {
            return [];
        }
        $identifier = $originalIdentifier ?? $user->getUserIdentifier();

        return ['u-' . $user->getUserIdentifier(), 'old-' . $identifier, 'u-' . $user->getUserIdentifier(), '', "u-\0", str_repeat('x', 129)];
    }
}
