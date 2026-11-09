<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Blame;

use Doctrine\ORM\Event\OnFlushEventArgs;
use Nowo\LegalKitBundle\Entity\LegalDocument;
use Nowo\LegalKitBundle\Versioning\Entity\LegalPageVersion;

/**
 * `doctrine.blame: actor_id` only: stamps `created_by` / `updated_by` with the current actor id on flush. Without a
 * signed-in user (CLI, anonymous) the values already set on the row are kept (for example the `$actor` given to an
 * import); a signed-in user that cannot be recorded writes null.
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
        // No signed-in user (CLI, anonymous): keep the values already on the row. A signed-in user whose id cannot be
        // recorded (class not accepted, unusable id) writes null: the edit is not credited to the previous editor (JX-L4).
        if (!$this->actorIds->hasCurrentActor()) {
            return;
        }
        $actorId = $this->actorIds->currentActorId();
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
