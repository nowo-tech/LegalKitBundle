<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Acceptance;

/**
 * The signed-in user as the bundle hands it to {@see LegalSubjectIdResolverInterface} (1.12, architecture bar rule 3):
 * no security type in the port. `identifier` is the security user identifier, `userClass` the class of the
 * authenticated user object, `roles` its roles, and `principal` that object itself, typed `object`: a DDD host checks
 * it against its own user class (or ignores it and resolves the subject from `identifier`).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class LegalSubjectUser
{
    /**
     * @param string $identifier Security user identifier
     * @param class-string $userClass Class of the authenticated user object
     * @param list<string> $roles Roles of the user
     * @param object $principal The authenticated user object (your class)
     */
    public function __construct(
        public string $identifier,
        public string $userClass,
        public array $roles,
        public object $principal,
    ) {
    }
}
