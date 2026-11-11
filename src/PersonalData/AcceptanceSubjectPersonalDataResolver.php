<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\PersonalData;

use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceSubjectResolverInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Default {@see LegalPersonalDataSubjectResolverInterface} (1.11): the id the host's
 * {@see LegalAcceptanceSubjectResolverInterface} (`acceptance.subject_resolver`) gives for the user, i.e. the id the
 * bundle records the user's acceptances under (Beacon: the account uuid). A user that is not a `UserInterface`, or that
 * the host resolver leaves out (null), has no subject.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class AcceptanceSubjectPersonalDataResolver implements LegalPersonalDataSubjectResolverInterface
{
    /**
     * @param LegalAcceptanceSubjectResolverInterface $resolver The host resolver of `acceptance.subject_resolver`
     */
    public function __construct(
        private LegalAcceptanceSubjectResolverInterface $resolver,
    ) {
    }

    public function subjectsFor(object $user, ?string $originalIdentifier): array
    {
        if (!$user instanceof UserInterface) {
            return [];
        }
        $id = $this->resolver->resolve($user);

        return $id === null || $id === '' ? [] : [$id];
    }
}
