<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Integration;

use ArrayObject;
use Nowo\LegalKitBundle\Entity\LegalDocument;
use Nowo\LegalKitBundle\Placeholder\LegalPlaceholderScanner;
use Nowo\LegalKitBundle\Placeholder\PlaceholderHit;
use Nowo\LegalKitBundle\Tests\Support\KernelTestCase;
use Nowo\LegalKitBundle\Tests\Support\TestKernel;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\CommandLoader\FactoryCommandLoader;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\EventListener\ResetServicesListener;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Worker;

use function array_filter;
use function gc_collect_cycles;
use function is_array;
use function memory_get_usage;
use function sprintf;

/**
 * Kept-alive kernel, services resetter never called (a host that removes or bypasses services_resetter,
 * `messenger:consume --no-reset`, long-running console processes): one booted kernel, the same
 * {@see LegalPlaceholderScanner}, many units of work.
 *
 * Before each unit "another worker" edits the "notice" page, alternating two versions: version A still carries a seed
 * marker (`privacy@example.com`), version B does not. Each unit asks the scanner whether "notice" has placeholders and
 * must get the answer of the version stored now, not the memo of the previous unit, nor (1.12) a legal entity the
 * bundle's private entity manager kept from it: the edit is a plain DBAL update, so only the boundary clears of the
 * scanner memo and of the private manager make the next unit see it.
 *
 * N = 500 per path: HTTP through the http_kernel service (not Kernel::handle(), so no resetter; with a sub-request),
 * Messenger through a real Worker without ResetServicesListener (every 7th handler fails), and console Application
 * runs. Memory margin: 1 MiB after a 50-unit warm-up.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class WorkerBoundariesTest extends KernelTestCase
{
    private const ITERATIONS    = 500;
    private const WARMUP        = 50;
    private const MEMORY_MARGIN = 1024 * 1024;
    private const MARKER        = 'privacy@example.com';

    /** @var list<string> */
    private array $leaks = [];

    private int $memoryAtWarmup = 0;

    private int $memoryAtEnd = 0;

    public function testManyRequestsAlwaysSeeTheCurrentPage(): void
    {
        [$kernel, $scanner] = $this->boot();

        $http = $kernel->getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        self::assertInstanceOf(TerminableInterface::class, $http);

        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            $this->edit($kernel, $i);
            $request = Request::create('/legal/notice');
            $request->attributes->set('_controller', function () use ($http, $scanner, $i): Response {
                $this->unitOfWork($scanner, $i);
                // A sub-request (fragment) keeps the memo of its main request.
                $fragment = Request::create('/_fragment');
                $fragment->attributes->set('_controller', function () use ($scanner, $i): Response {
                    $this->unitOfWork($scanner, $i);

                    return new Response('fragment');
                });

                return $http->handle($fragment, HttpKernelInterface::SUB_REQUEST);
            });
            $response = $http->handle($request);
            self::assertSame(200, $response->getStatusCode());
            $http->terminate($request, $response);
        }

        $this->assertNoLeakAndFlatMemory('HTTP requests');
    }

    public function testManyMessengerMessagesAlwaysSeeTheCurrentPage(): void
    {
        [$kernel, $scanner, $dispatcher] = $this->boot();

        foreach ($dispatcher->getListeners(WorkerRunningEvent::class) as $listener) {
            self::assertFalse(
                is_array($listener) && $listener[0] instanceof ResetServicesListener,
                'The worker must run without ResetServicesListener (messenger:consume --no-reset).',
            );
        }

        $receiver = new class(self::ITERATIONS) implements ReceiverInterface {
            public int $handled = 0;
            private int $next   = 0;

            public function __construct(private readonly int $count)
            {
            }

            public function get(): iterable
            {
                return $this->next < $this->count ? [new Envelope(new ArrayObject(['index' => $this->next++]))] : [];
            }

            public function ack(Envelope $envelope): void
            {
                ++$this->handled;
            }

            public function reject(Envelope $envelope): void
            {
                ++$this->handled;
            }
        };

        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            ArrayObject::class => [function (ArrayObject $message) use ($kernel, $scanner): void {
                $index = (int) $message['index'];
                $this->unitOfWork($scanner, $index);
                // The next message runs after another worker's edit.
                $this->edit($kernel, $index + 1);
                if ($index % 7 === 3) {
                    throw new RuntimeException('Handler failed');
                }
            }],
        ]))]);

        $this->edit($kernel, 0);
        $worker     = new Worker(['async' => $receiver], $bus, $dispatcher);
        $stopOnIdle = static function (WorkerRunningEvent $event): void {
            if ($event->isWorkerIdle()) {
                $event->getWorker()->stop();
            }
        };
        $dispatcher->addListener(WorkerRunningEvent::class, $stopOnIdle);

        try {
            $worker->run(['sleep' => 0]);
        } finally {
            $dispatcher->removeListener(WorkerRunningEvent::class, $stopOnIdle);
        }

        self::assertSame(self::ITERATIONS, $receiver->handled);
        $this->assertNoLeakAndFlatMemory('Messenger messages');
    }

    public function testManyConsoleRunsAlwaysSeeTheCurrentPage(): void
    {
        [$kernel, $scanner, $dispatcher] = $this->boot();

        $command = (new Command('app:check-notice'))
            ->addArgument('index', InputArgument::REQUIRED)
            ->setCode(function (InputInterface $input, OutputInterface $output) use ($scanner): int {
                $this->unitOfWork($scanner, (int) $input->getArgument('index'));

                return Command::SUCCESS;
            });

        $application = new Application('worker-test');
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $application->setDispatcher($dispatcher);
        $application->setCommandLoader(new FactoryCommandLoader(['app:check-notice' => static fn (): Command => $command]));

        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            $this->edit($kernel, $i);
            self::assertSame(Command::SUCCESS, $application->run(new ArrayInput(['command' => 'app:check-notice', 'index' => (string) $i]), new NullOutput()));
        }

        $this->assertNoLeakAndFlatMemory('console command runs');
    }

    /**
     * @return array{0: TestKernel, 1: LegalPlaceholderScanner, 2: EventDispatcherInterface}
     */
    private function boot(): array
    {
        $kernel  = $this->bootKernel(['locales' => ['en'], 'brand_name' => 'Acme']);
        $scanner = $this->testContainer($kernel)->get(LegalPlaceholderScanner::class);
        self::assertInstanceOf(LegalPlaceholderScanner::class, $scanner);
        $dispatcher = $kernel->getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $this->store($kernel, 'notice', 'en', 'Notice', '<p>Acme Ltd</p>');
        $this->leaks          = [];
        $this->memoryAtWarmup = 0;
        $this->memoryAtEnd    = 0;

        return [$kernel, $scanner, $dispatcher];
    }

    /**
     * Another worker stores version A (with a seed marker) for even units and version B (clean) for odd units.
     */
    private function edit(TestKernel $kernel, int $index): void
    {
        // 1.12: another worker's write reaches the table only (a DBAL update, no identity map of this process), so a
        // legal entity the bundle's private manager kept from an earlier unit would answer the stale version.
        $updated = $this->entityManager($kernel)->getConnection()->update(
            LegalDocument::TABLE,
            ['body' => $index % 2 === 0 ? '<p>Write to ' . self::MARKER . '</p>' : '<p>Write to legal@acme.test</p>'],
            ['slug' => 'notice', 'locale' => 'en'],
        );
        self::assertSame(1, (int) $updated);
    }

    private function unitOfWork(LegalPlaceholderScanner $scanner, int $index): void
    {
        if ($index === self::WARMUP) {
            gc_collect_cycles();
            $this->memoryAtWarmup = memory_get_usage();
        }

        $noticeHits = array_filter($scanner->findHits('en'), static fn (PlaceholderHit $hit): bool => $hit->slug === 'notice');
        $expected   = $index % 2 === 0;
        if (($noticeHits !== []) !== $expected) {
            $this->leaks[] = sprintf('unit %d saw version %s of the notice page', $index, $expected ? 'B' : 'A');
        }

        if ($index === self::ITERATIONS - 1) {
            gc_collect_cycles();
            $this->memoryAtEnd = memory_get_usage();
        }
    }

    private function assertNoLeakAndFlatMemory(string $path): void
    {
        self::assertSame([], $this->leaks, sprintf('Stale placeholder hits between %s.', $path));
        self::assertGreaterThan(0, $this->memoryAtWarmup);
        self::assertLessThan(self::MEMORY_MARGIN, $this->memoryAtEnd - $this->memoryAtWarmup, sprintf('Memory grew across %s.', $path));
    }
}
