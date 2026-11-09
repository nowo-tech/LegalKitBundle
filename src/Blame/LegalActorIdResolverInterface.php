<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Blame;

use Nowo\LegalKitBundle\Portability\LegalDocumentPortability;

/**
 * Port: the actor id written to `created_by` / `updated_by` with `nowo_legal_kit.doctrine.blame: actor_id`. The
 * default ({@see SecurityLegalActorIdResolver}) reads the signed-in Symfony user; override the alias to return the id
 * of your own user aggregate (never an e-mail if you can avoid it).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface LegalActorIdResolverInterface
{
    /** Maximum length of an actor id (VARCHAR 191). */
    public const int MAX_LENGTH = 191;

    /**
     * Actor id of the current request / process; null when nobody is signed in (CLI, anonymous): the bundle then keeps
     * the value already set on the row.
     *
     * @return ?string The actor id (at most {@see MAX_LENGTH} characters)
     */
    public function currentActorId(): ?string;

    /**
     * Actor id of an explicit actor (the `$actor` of {@see LegalDocumentPortability::apply()}).
     *
     * @return ?string The actor id, null when the object is not an actor
     */
    public function actorIdFor(object $actor): ?string;
}
