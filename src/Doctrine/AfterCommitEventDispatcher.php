<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Doctrine;

use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;
use WeakMap;

use function array_slice;
use function count;
use function strcasecmp;

/**
 * Dispatches the bundle's events only once the write is committed (1.10, architecture bar rule 7; same design as
 * InstanceSettingsBundle 1.10).
 *
 * Without an open database transaction on the bundle's connection the event is dispatched at once (the bundle's own
 * write has already committed). Inside a transaction (one the host opened around {@see LegalAcceptanceManagerInterface::record()}
 * or `eraseFor()`) the event is held by the innermost level and dispatched after the **outermost commit**; a rollback
 * (of the transaction or of the savepoint that held it) drops it. The transactions are reported by
 * {@see AfterCommitMiddleware} for the bundle's Doctrine connection only (the one of `doctrine.entity_manager`).
 *
 * The state is keyed weakly by the driver connection: a connection closed or dropped in the middle of a transaction
 * (`Connection::close()` never rolls back) takes its pending events with it instead of holding every later dispatch.
 *
 * A listener that throws during a deferred dispatch cannot fail the commit that already happened: the error is logged
 * and the remaining events are still dispatched. Worker-safe: nothing outlives the transaction that held it, and the
 * pending events are also dropped on kernel.reset.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class AfterCommitEventDispatcher implements EventDispatcherInterface, TransactionObserverInterface, ResetInterface
{
    /**
     * Open transactions by driver connection, in the order they began: the real transaction, then one level per
     * savepoint, each with the events dispatched while it was the innermost level.
     *
     * @var WeakMap<object, list<array{0: string|null, 1: list<object>}>>
     */
    private WeakMap $transactions;

    /**
     * @param EventDispatcherInterface|null $dispatcher the application dispatcher (null = events are dropped)
     * @param LoggerInterface|null $logger receives the errors of deferred listeners
     */
    public function __construct(
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->transactions = new WeakMap();
    }

    /**
     * Dispatches now (no open transaction) or holds the event until the outermost commit.
     */
    public function dispatch(object $event): object
    {
        $connection = null;
        foreach ($this->transactions as $open => $levels) {
            // The most recently started live transaction.
            $connection = $open;
        }
        if ($connection === null) {
            return $this->dispatcher?->dispatch($event) ?? $event;
        }
        $levels        = $this->transactions[$connection];
        $last          = count($levels) - 1;
        [$name, $held] = $levels[$last];
        $held[]        = $event;
        $levels[$last] = [$name, $held];
        // @igor-ignore - Pending events of the open transaction; dropped on commit / rollback and on kernel.reset.
        $this->transactions[$connection] = $levels;

        return $event;
    }

    /**
     * A real transaction started: a new, empty stack of levels.
     */
    public function transactionStarted(object $connection): void
    {
        unset($this->transactions[$connection]);
        // @igor-ignore - Pending events of the open transaction; dropped on commit / rollback and on kernel.reset.
        $this->transactions[$connection] = [[null, []]];
    }

    /**
     * The real transaction ended: a commit dispatches every event it holds, a rollback drops them.
     */
    public function transactionEnded(object $connection, bool $committed): void
    {
        $levels = $this->transactions[$connection] ?? [];
        unset($this->transactions[$connection]);
        if (!$committed) {
            return;
        }
        foreach ($this->flatten($levels) as $event) {
            try {
                $this->dispatcher?->dispatch($event);
            } catch (Throwable $e) {
                $this->logger?->error('Legal kit: a listener failed after the commit.', [
                    'bundle'    => 'NowoLegalKitBundle',
                    'event'     => $event::class,
                    'exception' => $e::class,
                ]);
            }
        }
    }

    /**
     * A savepoint was created: a new innermost level (a still open level of the same name was implicitly committed).
     */
    public function savepointCreated(object $connection, string $name): void
    {
        if (!isset($this->transactions[$connection])) {
            return;
        }
        $this->closeSavepoint($connection, $name, true);
        $levels   = $this->transactions[$connection];
        $levels[] = [$name, []];
        // @igor-ignore - Pending events of the open transaction; dropped on commit / rollback and on kernel.reset.
        $this->transactions[$connection] = $levels;
    }

    /**
     * A savepoint was released: its events go to the enclosing level.
     */
    public function savepointReleased(object $connection, string $name): void
    {
        $this->closeSavepoint($connection, $name, true);
    }

    /**
     * Rolled back to a savepoint: the events of that level (and of the levels inside it) are dropped.
     */
    public function savepointRolledBack(object $connection, string $name): void
    {
        $this->closeSavepoint($connection, $name, false);
    }

    /**
     * Drops every pending event (kernel.reset: a worker request that died mid-transaction).
     */
    public function reset(): void
    {
        $this->transactions = new WeakMap();
    }

    private function closeSavepoint(object $connection, string $name, bool $committed): void
    {
        $levels = $this->transactions[$connection] ?? [];
        foreach ($levels as $index => [$level]) {
            if ($index > 0 && $level !== null && strcasecmp($level, $name) === 0) {
                $closed = $this->flatten(array_slice($levels, $index));
                $levels = array_slice($levels, 0, $index);
                if ($committed) {
                    $parent             = count($levels) - 1;
                    $levels[$parent][1] = [...$levels[$parent][1], ...$closed];
                }
                // @igor-ignore - Pending events of the open transaction; dropped on commit / rollback and on kernel.reset.
                $this->transactions[$connection] = $levels;

                return;
            }
        }
    }

    /**
     * @param list<array{0: string|null, 1: list<object>}> $levels
     *
     * @return list<object>
     */
    private function flatten(array $levels): array
    {
        $events = [];
        foreach ($levels as [, $level]) {
            $events = [...$events, ...$level];
        }

        return $events;
    }
}
