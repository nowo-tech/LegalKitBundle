<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;

/**
 * What a test saw of the bundle's private manager during one request (see KernelTestCase::watchDirtyRowsAtResponse()).
 */
final class ResponseProbe
{
    /** Managed rows with a pending change when the response was built (null: no response yet). */
    public ?int $dirty = null;

    /** The inner manager the request started with. */
    public ?EntityManagerInterface $inner = null;
}
