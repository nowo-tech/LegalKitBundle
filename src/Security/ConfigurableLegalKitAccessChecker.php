<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Security;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Default checker: granted when the user has at least one of `security.access_roles`. An empty list denies
 * (fail closed); use a custom checker or the firewall for other rules.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class ConfigurableLegalKitAccessChecker implements LegalKitAccessCheckerInterface
{
    /**
     * @param AuthorizationCheckerInterface $authorizationChecker Symfony authorization checker
     * @param list<string> $accessRoles `security.access_roles`
     */
    public function __construct(
        private AuthorizationCheckerInterface $authorizationChecker,
        private array $accessRoles,
    ) {
    }

    /**
     * Grants access when the user has one of the configured roles.
     */
    public function canAccess(): bool
    {
        foreach ($this->accessRoles as $role) {
            if ($this->authorizationChecker->isGranted($role)) {
                return true;
            }
        }

        return false;
    }
}
