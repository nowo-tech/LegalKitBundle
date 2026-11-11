<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Acceptance;

use Nowo\LegalKitBundle\Acceptance\Entity\LegalAcceptance;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\User\UserInterface;

use function preg_match;

/**
 * The subject of the current security token, through the host resolver. Null without a token, a user, or a valid id.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class CurrentLegalSubject
{
    /**
     * @param LegalAcceptanceSubjectResolverInterface|LegalSubjectIdResolverInterface $resolver Host resolver (the
     *                                                                                          deprecated form until 2.0)
     * @param ?TokenStorageInterface $tokenStorage Security token storage (null without SecurityBundle)
     * @param LoggerInterface $logger Logger (never the id)
     */
    public function __construct(
        private LegalSubjectIdResolverInterface|LegalAcceptanceSubjectResolverInterface $resolver,
        private ?TokenStorageInterface $tokenStorage = null,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Whether a user is signed in.
     */
    public function isAuthenticated(): bool
    {
        return $this->tokenStorage?->getToken()?->getUser() instanceof UserInterface;
    }

    /**
     * Whether the current token is an impersonation (switch user): nobody may accept on someone else's behalf.
     */
    public function isImpersonating(): bool
    {
        return $this->tokenStorage?->getToken() instanceof SwitchUserToken;
    }

    /**
     * Subject id of the signed-in user (null = not subject to acceptance).
     *
     * @return ?string The id
     */
    public function id(): ?string
    {
        $user = $this->tokenStorage?->getToken()?->getUser();
        if (!$user instanceof UserInterface) {
            return null;
        }
        $id = self::resolveSubject($this->resolver, $user);
        if ($id === null) {
            return null;
        }
        if (preg_match(LegalAcceptance::SUBJECT_PATTERN, $id) !== 1) {
            $this->logger->warning('Legal acceptance subject id rejected.', ['bundle' => 'NowoLegalKitBundle', 'action' => 'acceptance_subject_invalid']);

            return null;
        }

        return $id;
    }

    /**
     * The subject id of a security user through either form of the host resolver (1.12: the framework-free
     * {@see LegalSubjectIdResolverInterface} wins).
     */
    public static function resolveSubject(LegalSubjectIdResolverInterface|LegalAcceptanceSubjectResolverInterface $resolver, UserInterface $user): ?string
    {
        if ($resolver instanceof LegalSubjectIdResolverInterface) {
            return $resolver->subjectIdFor(self::subjectUser($user));
        }

        return $resolver->resolve($user);
    }

    /**
     * The framework-free view of a security user (1.12).
     */
    public static function subjectUser(UserInterface $user): LegalSubjectUser
    {
        $roles = [];
        foreach ($user->getRoles() as $role) {
            $roles[] = (string) $role;
        }

        return new LegalSubjectUser($user->getUserIdentifier(), $user::class, $roles, $user);
    }
}
