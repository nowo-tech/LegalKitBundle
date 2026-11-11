<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\DriverManager;
use Nowo\LegalKitBundle\Doctrine\AfterCommitConnection;
use Nowo\LegalKitBundle\Doctrine\AfterCommitMiddleware;
use Nowo\LegalKitBundle\Doctrine\TransactionObserverInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The DBAL middleware reports the real transaction and its savepoints to its observers (1.10).
 */
final class AfterCommitMiddlewareTest extends TestCase
{
    public function testRealTransactionsAndSavepointsAreReported(): void
    {
        $observer   = $this->observer();
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], (new Configuration())->setMiddlewares([new AfterCommitMiddleware($observer)]));

        $connection->beginTransaction();
        $connection->beginTransaction();
        $connection->rollBack();
        $connection->beginTransaction();
        $connection->commit();
        $connection->commit();
        $connection->beginTransaction();
        $connection->rollBack();

        self::assertSame([
            'started',
            'savepoint DOCTRINE_2',
            'rolled back to DOCTRINE_2',
            'savepoint DOCTRINE_2',
            'released DOCTRINE_2',
            'ended committed',
            'started',
            'ended rolled back',
        ], $observer->log);
    }

    public function testSqlServerSavepointStatementsAndAFailedCommit(): void
    {
        $observer = $this->observer();
        $driver   = $this->createStub(Connection::class);
        $driver->method('exec')->willReturn(0);
        $driver->method('commit')->willThrowException(new RuntimeException('deadlock'));
        $connection = new AfterCommitConnection($driver, $observer);

        $connection->beginTransaction();
        self::assertSame(0, $connection->exec('SAVE TRANSACTION DOCTRINE_2'));
        $connection->exec('ROLLBACK TRANSACTION DOCTRINE_2');
        $connection->exec('SELECT 1');
        try {
            $connection->commit();
            self::fail('Expected the commit failure.');
        } catch (RuntimeException $e) {
            self::assertSame('deadlock', $e->getMessage());
        }

        self::assertSame(['started', 'savepoint DOCTRINE_2', 'rolled back to DOCTRINE_2', 'ended rolled back'], $observer->log);
    }

    public function testAThrowingObserverDoesNotHideACommitFailure(): void
    {
        $failing = new class implements TransactionObserverInterface {
            public function transactionStarted(object $connection): void
            {
            }

            public function transactionEnded(object $connection, bool $committed): void
            {
                throw new RuntimeException('observer');
            }

            public function savepointCreated(object $connection, string $name): void
            {
            }

            public function savepointReleased(object $connection, string $name): void
            {
            }

            public function savepointRolledBack(object $connection, string $name): void
            {
            }
        };
        $driver = $this->createStub(Connection::class);
        $driver->method('commit')->willThrowException(new RuntimeException('deadlock'));
        $connection = new AfterCommitConnection($driver, $failing);

        $this->expectExceptionMessage('deadlock');
        $connection->commit();
    }

    /**
     * @return object{log: list<string>}&TransactionObserverInterface
     */
    private function observer(): TransactionObserverInterface
    {
        return new class implements TransactionObserverInterface {
            /** @var list<string> */
            public array $log = [];

            public function transactionStarted(object $connection): void
            {
                $this->log[] = 'started';
            }

            public function transactionEnded(object $connection, bool $committed): void
            {
                $this->log[] = $committed ? 'ended committed' : 'ended rolled back';
            }

            public function savepointCreated(object $connection, string $name): void
            {
                $this->log[] = 'savepoint ' . $name;
            }

            public function savepointReleased(object $connection, string $name): void
            {
                $this->log[] = 'released ' . $name;
            }

            public function savepointRolledBack(object $connection, string $name): void
            {
                $this->log[] = 'rolled back to ' . $name;
            }
        };
    }
}
