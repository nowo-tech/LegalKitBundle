<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Blame;

/**
 * String blame columns (`created_by` / `updated_by`) used with `nowo_legal_kit.doctrine.blame: actor_id`. The
 * properties are mapped by {@see BlameMappingListener} only in that mode; in the default `relation` mode they stay
 * unmapped and null.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
trait ActorIdBlameTrait
{
    /** Actor id of the creator (`doctrine.blame: actor_id` only). */
    private ?string $createdByActorId = null;

    /** Actor id of the last editor (`doctrine.blame: actor_id` only). */
    private ?string $updatedByActorId = null;

    /**
     * Actor id of the creator (null outside the `actor_id` blame mode or when unknown).
     *
     * @return ?string The actor id
     */
    public function getCreatedByActorId(): ?string
    {
        return $this->createdByActorId;
    }

    /**
     * Sets the actor id of the creator (`actor_id` blame mode).
     *
     * @return void Nothing (side effects only)
     */
    public function setCreatedByActorId(?string $actorId): void
    {
        // @igor-ignore - Entity state; instance-scoped, not a shared service.
        $this->createdByActorId = $actorId;
    }

    /**
     * Actor id of the last editor (null outside the `actor_id` blame mode or when unknown).
     *
     * @return ?string The actor id
     */
    public function getUpdatedByActorId(): ?string
    {
        return $this->updatedByActorId;
    }

    /**
     * Sets the actor id of the last editor (`actor_id` blame mode).
     *
     * @return void Nothing (side effects only)
     */
    public function setUpdatedByActorId(?string $actorId): void
    {
        // @igor-ignore - Entity state; instance-scoped, not a shared service.
        $this->updatedByActorId = $actorId;
    }
}
