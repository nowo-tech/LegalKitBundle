<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Unit\DependencyInjection\Compiler;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\MiddlewaresPass;
use Nowo\LegalKitBundle\DependencyInjection\Compiler\AfterCommitMiddlewarePass;
use Nowo\LegalKitBundle\DependencyInjection\NowoLegalKitExtension;
use Nowo\LegalKitBundle\Doctrine\AfterCommitMiddleware;
use Nowo\LegalKitBundle\EventSubscriber\LegalKitConflictListener;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\Dbal\CommitTrackingMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * 1.14 (REQ-RUNTIME-003, audit UE-CF-1): the commit tracking of the conflict answer wraps the bundle's connection only
 * (the one the after-commit middleware follows), or reuses transaction-doctrine's ledger. DoctrineBundle's real
 * MiddlewaresPass builds the per-connection middlewares.
 */
#[CoversClass(AfterCommitMiddlewarePass::class)]
final class CommitTrackingScopeTest extends TestCase
{
    private const string MIDDLEWARE = NowoLegalKitExtension::COMMIT_TRACKING_MIDDLEWARE;

    public function testByDefaultOnlyTheConnectionOfTheBundleEntityManagerIsWrapped(): void
    {
        $container = $this->container('bundle');
        $container->setParameter(NowoLegalKitExtension::ENTITY_MANAGER_PARAMETER, 'legal');

        $this->compile($container);

        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.legal'), 'the bundle connection');
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.default'), 'never the host connection');
        self::assertSame(NowoLegalKitExtension::COMMIT_LEDGER_SERVICE, (string) $container->getDefinition(LegalKitConflictListener::class)->getArgument(1));
    }

    public function testTheConnectionOptionOfThePrivateManagerIsWrapped(): void
    {
        $container = $this->container('bundle');
        $container->setParameter(NowoLegalKitExtension::CONNECTION_PARAMETER, 'legal');
        $container->register('doctrine.dbal.legal_connection');

        $this->compile($container);

        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.legal'));
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.default'));
    }

    public function testWithoutAParameterTheModeIsBundleAndTheDefaultManagerConnectionIsWrapped(): void
    {
        $container = $this->container('bundle');
        $container->getParameterBag()->remove(NowoLegalKitExtension::COMMIT_TRACKING_PARAMETER);

        $this->compile($container);

        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.default'));
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.legal'));
    }

    public function testAllWrapsEveryConnection(): void
    {
        $container = $this->container('all');
        $container->setParameter(NowoLegalKitExtension::ENTITY_MANAGER_PARAMETER, 'legal');

        $this->compile($container);

        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.legal'));
        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.default'));
    }

    public function testTransactionDoctrineCommitDetectionIsReusedAndNoConnectionIsWrappedTwice(): void
    {
        $container = $this->container('all');
        $container->setDefinition('nowo_transaction_doctrine.commit_ledger', new Definition(CommitLedger::class));
        $container->setAlias(CommitLedger::class, 'nowo_transaction_doctrine.commit_ledger');

        $this->compile($container);

        self::assertFalse($container->hasDefinition(self::MIDDLEWARE));
        self::assertFalse($container->hasDefinition(NowoLegalKitExtension::COMMIT_LEDGER_SERVICE));
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.legal'));
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.default'));
        self::assertSame(CommitLedger::class, (string) $container->getDefinition(LegalKitConflictListener::class)->getArgument(1));
        self::assertTrue($container->hasDefinition(AfterCommitMiddleware::class . '.default'), 'the after-commit middleware stays');
    }

    public function testTransactionDoctrineLedgerWithoutTheListenerOnlyDropsTheMiddleware(): void
    {
        $container = $this->container('bundle');
        $container->removeDefinition(LegalKitConflictListener::class);
        $container->setDefinition(CommitLedger::class, new Definition(CommitLedger::class));

        (new AfterCommitMiddlewarePass())->process($container);

        self::assertFalse($container->hasDefinition(self::MIDDLEWARE));
    }

    public function testOffRegistersNothingAndThePassOnlyScopesTheAfterCommitMiddleware(): void
    {
        $container = $this->container('off');
        $container->removeDefinition(self::MIDDLEWARE);
        $container->removeDefinition(NowoLegalKitExtension::COMMIT_LEDGER_SERVICE);
        $container->setDefinition(CommitLedger::class, new Definition(CommitLedger::class));

        $this->compile($container);

        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.default'));
        self::assertTrue($container->hasDefinition(AfterCommitMiddleware::class . '.default'));
        self::assertNull($container->getDefinition(LegalKitConflictListener::class)->getArgument(1), 'no ledger to replace');
    }

    private function container(string $mode): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(NowoLegalKitExtension::COMMIT_TRACKING_PARAMETER, $mode);
        $container->setParameter(NowoLegalKitExtension::ENTITY_MANAGER_PARAMETER, null);
        $container->setParameter('doctrine.connections', ['default' => 'doctrine.dbal.default_connection', 'legal' => 'doctrine.dbal.legal_connection']);
        $container->setDefinition('doctrine.dbal.default_connection.configuration', new Definition());
        $container->setDefinition('doctrine.dbal.legal_connection.configuration', new Definition());
        $container->setDefinition('doctrine.orm.default_entity_manager', new Definition(null, [new Reference('doctrine.dbal.default_connection')]));
        $container->setDefinition('doctrine.orm.legal_entity_manager', new Definition(null, [new Reference('doctrine.dbal.legal_connection')]));
        $container->register(AfterCommitMiddleware::class, AfterCommitMiddleware::class)->addTag('doctrine.middleware');
        $container->setDefinition(NowoLegalKitExtension::COMMIT_LEDGER_SERVICE, new Definition(CommitLedger::class));
        $container->setDefinition(self::MIDDLEWARE, (new Definition(CommitTrackingMiddleware::class, [new Reference(NowoLegalKitExtension::COMMIT_LEDGER_SERVICE)]))
            ->addTag('doctrine.middleware'));
        $container->setDefinition(LegalKitConflictListener::class, new Definition(LegalKitConflictListener::class, [null, $mode === 'off' ? null : new Reference(NowoLegalKitExtension::COMMIT_LEDGER_SERVICE), null]));

        return $container;
    }

    private function compile(ContainerBuilder $container): void
    {
        (new AfterCommitMiddlewarePass())->process($container);
        (new MiddlewaresPass())->process($container);
    }
}
