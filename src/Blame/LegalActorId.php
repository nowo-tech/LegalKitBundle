<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Blame;

use InvalidArgumentException;
use Nowo\LegalKitBundle\Portability\LegalDocumentPortabilityInterface;

use function mb_strlen;
use function sprintf;
use function trim;

/**
 * An actor given by id (1.10, architecture bar rule 3): pass it as the `$actor` of
 * {@see LegalDocumentPortabilityInterface::apply()} instead of a user entity. With `doctrine.blame: actor_id` the id is
 * written to `created_by` / `updated_by`; no user object, Doctrine relation or security token is involved.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class LegalActorId
{
    /**
     * @param string $id Actor id (1 to {@see LegalActorIdResolverInterface::MAX_LENGTH} characters, never an e-mail
     *                   if you can avoid it)
     */
    public function __construct(
        public string $id,
    ) {
        if (trim($id) === '' || mb_strlen($id) > LegalActorIdResolverInterface::MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf('A legal actor id has 1 to %d characters.', LegalActorIdResolverInterface::MAX_LENGTH));
        }
    }
}
