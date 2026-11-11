<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Support\DddHost;

/**
 * A DDD host's account: plain PHP, mapped in XML (config/Account.orm.xml), no bundle base class, trait or interface.
 */
class Account
{
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    public function __construct(
        private string $publicId,
    ) {
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function publicId(): string
    {
        return $this->publicId;
    }
}
