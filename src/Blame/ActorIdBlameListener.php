<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Blame;

use Doctrine\ORM\Event\OnFlushEventArgs;
use Nowo\LegalKitBundle\Entity\LegalDocument;
use Nowo\LegalKitBundle\Versioning\Entity\LegalPageVersion;

/**
 * `doctrine.blame: actor_id` only: stamps `created_by` / `updated_by` with the current actor id on flush. Without a
 * current actor (CLI, anonymous) the values already set on the row are kept (for example the `$actor` given to an
 * import).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class ActorIdBlameListener
{
    /**
     * @param LegalActorIdResolverInterface $actorIds Current actor id
     */
    public function __construct(
        private LegalActorIdResolverInterface $actorIds,
    ) {
    }

    /**
     * Stamps the scheduled inserts and updates of the bundle entities.
     *
     * @return void Nothing (side effects only)
     */
    public function onFlush(OnFlushEventArgs $event): void
    {
        $actorId = $this->actorIds->currentActorId();
        if ($actorId === null) {
            return;
        }
        $manager = $event->getObjectManager();
        $uow     = $manager->getUnitOfWork();
        foreach ([[true, $uow->getScheduledEntityInsertions()], [false, $uow->getScheduledEntityUpdates()]] as [$insert, $entities]) {
            foreach ($entities as $entity) {
                if (!$entity instanceof LegalDocument && !$entity instanceof LegalPageVersion) {
                    continue;
                }
                if ($insert) {
                    $entity->setCreatedByActorId($actorId);
                }
                $entity->setUpdatedByActorId($actorId);
                $uow->recomputeSingleEntityChangeSet($manager->getClassMetadata($entity::class), $entity);
            }
        }
    }
}
