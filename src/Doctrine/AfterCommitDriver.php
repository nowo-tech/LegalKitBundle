<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

use function array_values;

/**
 * Driver of {@see AfterCommitMiddleware}: wraps each connection in a {@see AfterCommitConnection}.
 */
final class AfterCommitDriver extends AbstractDriverMiddleware
{
    /** @var list<TransactionObserverInterface> */
    private readonly array $observers;

    /**
     * @param Driver $driver wrapped driver
     * @param TransactionObserverInterface ...$observers told about the transactions, in this order
     */
    public function __construct(
        Driver $driver,
        TransactionObserverInterface ...$observers,
    ) {
        parent::__construct($driver);
        $this->observers = array_values($observers);
    }

    /**
     * Connects and wraps the connection.
     */
    public function connect(array $params): Connection
    {
        return new AfterCommitConnection(parent::connect($params), ...$this->observers);
    }
}
