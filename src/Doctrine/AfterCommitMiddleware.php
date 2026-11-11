<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

use function array_values;

/**
 * DBAL middleware of the bundle's connection (1.10): tells its observers ({@see AfterCommitEventDispatcher}) when a
 * **real** database transaction starts, commits or rolls back, and about its savepoints, so the bundle's events are
 * dispatched after the outermost commit. Registered on the connection of the bundle's entity manager only.
 * Stateless (worker-safe): the state lives in the observers.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class AfterCommitMiddleware implements Middleware
{
    /** @var list<TransactionObserverInterface> */
    private array $observers;

    /**
     * @param TransactionObserverInterface ...$observers told about the transactions, in this order
     */
    public function __construct(TransactionObserverInterface ...$observers)
    {
        $this->observers = array_values($observers);
    }

    /**
     * Wraps the driver so its connections report their transactions.
     */
    public function wrap(Driver $driver): Driver
    {
        return new AfterCommitDriver($driver, ...$this->observers);
    }
}
