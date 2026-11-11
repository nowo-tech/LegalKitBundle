<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Unit\Doctrine;

use ArrayObject;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\DriverManager;
use Nowo\LegalKitBundle\Doctrine\AfterCommitEventDispatcher;
use Nowo\LegalKitBundle\Doctrine\AfterCommitMiddleware;
use Nowo\LegalKitBundle\Doctrine\TransactionObserverInterface;
use Nowo\LegalKitBundle\Event\LegalAcceptancesErasedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpKernel\KernelEvents;

use function gc_collect_cycles;

/**
 * 1.10 (architecture bar rule 7): events after the outermost commit of the bundle's connection, dropped on rollback.
 */
final class AfterCommitEventDispatcherTest extends TestCase
{
    /** @var list<string> */
    private array $seen = [];

    private EventDispatcher $inner;

    private AfterCommitEventDispatcher $dispatcher;

    private ?DbalConnection $conn = null;

    protected function setUp(): void
    {
        $this->inner = new EventDispatcher();
        $this->inner->addListener(LegalAcceptancesErasedEvent::class, function (LegalAcceptancesErasedEvent $event): void {
            $this->seen[] = $event->subjectId;
        });
        $this->dispatcher = new AfterCommitEventDispatcher($this->inner, null, fn (): ?DbalConnection => $this->conn);
    }

    public function testWithoutATransactionTheEventIsDispatchedAtOnce(): void
    {
        $event = new LegalAcceptancesErasedEvent('general', 1);

        self::assertSame($event, $this->dispatcher->dispatch($event));
        self::assertSame(['general'], $this->seen);
    }

    public function testHeldUntilTheCommitAndDroppedOnRollback(): void
    {
        $connection = $this->connection();

        $connection->beginTransaction();
        $this->dispatcher->dispatch(new LegalAcceptancesErasedEvent('rolled_back', 1));
        $connection->rollBack();
        self::assertSame([], $this->seen);

        $connection->beginTransaction();
        $this->dispatcher->dispatch(new LegalAcceptancesErasedEvent('general', 1));
        self::assertSame([], $this->seen, 'not before the commit');
        $connection->commit();
        self::assertSame([], $this->seen, 'committed, but never dispatched from inside the commit');
        self::assertSame(1, $this->dispatcher->countPending());

        // A new transaction is open: the committed event still waits (counted with the held one).
        $connection->beginTransaction();
        $this->dispatcher->dispatch(new LegalAcceptancesErasedEvent('held', 1));
        self::assertSame(2, $this->dispatcher->countPending());
        self::assertSame(0, $this->dispatcher->dispatchPending());
        $connection->rollBack();
        self::assertSame(1, $this->dispatcher->countPending());

        $this->dispatcher->dispatch(new LegalAcceptancesErasedEvent('after', 1));
        self::assertSame(['general', 'after'], $this->seen, 'no transaction left open');
    }

    public function testSavepointsAreFollowed(): void
    {
        $connection = $this->connection();

        // Savepoint rolled back, outer committed: only the outer event.
        $connection->beginTransaction();
        $this->dispatcher->dispatch(new LegalAcceptancesErasedEvent('outer', 1));
        $connection->beginTransaction();
        $this->dispatcher->dispatch(new LegalAcceptancesErasedEvent('inner', 1));
        $connection->rollBack();
        $connection->commit();
        self::assertSame(1, $this->dispatcher->dispatchPending());
        self::assertSame(['outer'], $this->seen);

        // Savepoint released, outer rolled back: nothing.
        $this->seen = [];
        $connection->beginTransaction();
        $connection->beginTransaction();
        $this->dispatcher->dispatch(new LegalAcceptancesErasedEvent('inner', 1));
        $connection->commit();
        $connection->rollBack();
        self::assertSame([], $this->seen);

        // Savepoint released, outer committed: once, after the outer commit.
        $connection->beginTransaction();
        $connection->beginTransaction();
        $this->dispatcher->dispatch(new LegalAcceptancesErasedEvent('inner', 1));
        $connection->commit();
        self::assertSame([], $this->seen, 'a savepoint is not the real commit');
        self::assertSame(0, $this->dispatcher->dispatchPending(), 'nothing while the transaction is open');
        $connection->commit();
        $this->dispatcher->onBoundary();
        self::assertSame(['inner'], $this->seen);
    }

    /**
     * Audit KI-I-L1: a connection closed in the middle of a transaction (no rollback) does not hold later dispatches.
     */
    public function testAConnectionClosedMidTransactionDoesNotHoldLaterEvents(): void
    {
        $connection = $this->connection();

        $connection->beginTransaction();
        $this->dispatcher->dispatch(new LegalAcceptancesErasedEvent('lost', 1));
        $connection->close();
        gc_collect_cycles();

        $this->dispatcher->dispatch(new LegalAcceptancesErasedEvent('after_close', 1));
        self::assertSame(['after_close'], $this->seen, 'immediate: the closed transaction is gone');

        $connection->beginTransaction();
        $this->dispatcher->dispatch(new LegalAcceptancesErasedEvent('reconnected', 1));
        $connection->commit();
        $this->dispatcher->postFlush();
        self::assertSame(['after_close', 'reconnected'], $this->seen);
    }

    /**
     * Audit KI-I-L2: an observer that throws does not keep the dispatcher from being told about the commit.
     */
    public function testAThrowingObserverDoesNotStopTheOthers(): void
    {
        $failing = new class implements TransactionObserverInterface {
            public function transactionStarted(object $connection): void
            {
            }

            public function transactionEnded(object $connection, bool $committed): void
            {
                throw new RuntimeException('observer failed after the commit');
            }

            public function savepointCreated(object $connection, string $name): void
            {
                throw new RuntimeException('savepoint observer failed');
            }

            public function savepointReleased(object $connection, string $name): void
            {
            }

            public function savepointRolledBack(object $connection, string $name): void
            {
            }
        };
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], (new Configuration())->setMiddlewares([new AfterCommitMiddleware($failing, $this->dispatcher)]));
        $this->conn = $connection;

        $connection->beginTransaction();
        $this->dispatcher->dispatch(new LegalAcceptancesErasedEvent('general', 1));
        try {
            $connection->commit();
            self::fail('Expected the observer failure.');
        } catch (RuntimeException $e) {
            self::assertSame('observer failed after the commit', $e->getMessage());
        }
        $this->dispatcher->dispatchPending();
        self::assertSame(['general'], $this->seen, 'the dispatcher was told although the first observer threw');

        $this->dispatcher->dispatch(new LegalAcceptancesErasedEvent('after', 1));
        self::assertSame(['general', 'after'], $this->seen, 'no stale transaction left');
    }

    public function testSavepointNotificationsWithoutATransactionAreIgnored(): void
    {
        $connection = new class {};
        $this->dispatcher->savepointCreated($connection, 'DOCTRINE_2');
        $this->dispatcher->savepointReleased($connection, 'DOCTRINE_2');
        $this->dispatcher->savepointRolledBack($connection, 'DOCTRINE_2');
        $this->dispatcher->transactionEnded($connection, true);

        $this->dispatcher->dispatch(new LegalAcceptancesErasedEvent('general', 1));
        self::assertSame(['general'], $this->seen);
    }

    public function testASameNameSavepointClosesTheOpenOne(): void
    {
        $connection = new class {};
        $this->dispatcher->transactionStarted($connection);
        $this->dispatcher->savepointCreated($connection, 'SP');
        $this->dispatcher->dispatch(new LegalAcceptancesErasedEvent('first', 1));
        $this->dispatcher->savepointCreated($connection, 'sp');
        $this->dispatcher->dispatch(new LegalAcceptancesErasedEvent('second', 1));
        $this->dispatcher->savepointRolledBack($connection, 'SP');
        $this->dispatcher->transactionEnded($connection, true);
        $this->dispatcher->dispatchPending();

        self::assertSame(['first'], $this->seen, 'the first level was implicitly committed into its parent');
    }

    public function testAFailingListenerAfterTheCommitIsLoggedAndTheOthersStillRun(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, string, array<mixed>}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message, $context];
            }
        };
        $this->dispatcher = new AfterCommitEventDispatcher($this->inner, $logger, fn (): ?DbalConnection => $this->conn);
        $this->inner->addListener(LegalAcceptancesErasedEvent::class, static function (LegalAcceptancesErasedEvent $event): void {
            if ($event->subjectId === 'broken') {
                throw new RuntimeException('listener failed with a secret detail');
            }
        }, -10);
        $connection = $this->connection();

        $connection->beginTransaction();
        $this->dispatcher->dispatch(new LegalAcceptancesErasedEvent('broken', 1));
        $this->dispatcher->dispatch(new LegalAcceptancesErasedEvent('general', 1));
        $connection->commit();
        self::assertSame(2, $this->dispatcher->dispatchPending());

        self::assertSame(['broken', 'general'], $this->seen);
        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0][0]);
        self::assertSame(['bundle' => 'NowoLegalKitBundle', 'event' => LegalAcceptancesErasedEvent::class, 'exception' => RuntimeException::class], $logger->records[0][2]);

        // Immediate dispatch keeps propagating listener errors (unchanged behaviour).
        $this->expectException(RuntimeException::class);
        $this->dispatcher->dispatch(new LegalAcceptancesErasedEvent('broken', 1));
    }

    public function testResetDropsPendingEventsAndANullDispatcherIsANoOp(): void
    {
        $connection = new class {};
        $this->dispatcher->transactionStarted($connection);
        $this->dispatcher->dispatch(new LegalAcceptancesErasedEvent('pending', 1));
        $this->dispatcher->reset();
        $this->dispatcher->transactionEnded($connection, true);
        self::assertSame([], $this->seen);

        $silent = new AfterCommitEventDispatcher();
        $event  = new LegalAcceptancesErasedEvent('general', 1);
        self::assertSame($event, $silent->dispatch($event));
        $silent->transactionStarted($connection);
        $silent->dispatch($event);
        $silent->transactionEnded($connection, true);
    }

    /**
     * W1-B: a listener that writes to the database during the deferred dispatch sees no open transaction and commits
     * its own row normally.
     */
    public function testAListenerThatWritesCommitsItsOwnRow(): void
    {
        $connection = $this->connection();
        $connection->executeStatement('CREATE TABLE audit_row (subject VARCHAR(20))');
        $active = new ArrayObject();
        $this->inner->addListener(LegalAcceptancesErasedEvent::class, static function (LegalAcceptancesErasedEvent $event) use ($connection, $active): void {
            $active->append($connection->isTransactionActive());
            $connection->transactional(static fn (DbalConnection $c): int|string => $c->insert('audit_row', ['subject' => $event->subjectId]));
        });

        $connection->beginTransaction();
        $this->dispatcher->dispatch(new LegalAcceptancesErasedEvent('committed', 1));
        $connection->commit();
        self::assertSame([], $active->getArrayCopy(), 'nothing from inside the commit');
        self::assertSame(1, $this->dispatcher->dispatchPending());

        self::assertSame([false], $active->getArrayCopy(), 'the listener runs outside any transaction');
        self::assertFalse($connection->isTransactionActive());
        self::assertSame(['committed'], $connection->fetchFirstColumn('SELECT subject FROM audit_row'));
        self::assertSame(0, $this->dispatcher->countPending(), 'the listener write held nothing');
    }

    /**
     * An event dispatched while DBAL still reports a transaction the middleware no longer tracks (inside a driver
     * commit) waits for the next safe point.
     */
    public function testAnEventDuringTheDriverCommitWaits(): void
    {
        $this->conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->conn->beginTransaction();
        $this->dispatcher->dispatch(new LegalAcceptancesErasedEvent('waits', 1));
        self::assertSame([], $this->seen);
        self::assertSame(0, $this->dispatcher->dispatchPending());
        $this->conn->commit();
        self::assertSame(1, $this->dispatcher->dispatchPending());
        self::assertSame(['waits'], $this->seen);
        self::assertArrayHasKey(KernelEvents::TERMINATE, AfterCommitEventDispatcher::getSubscribedEvents());
    }

    private function connection(): DbalConnection
    {
        return $this->conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], (new Configuration())->setMiddlewares([new AfterCommitMiddleware($this->dispatcher)]));
    }
}
