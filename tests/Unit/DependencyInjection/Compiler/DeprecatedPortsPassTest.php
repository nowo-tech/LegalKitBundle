<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\LegalKitBundle\Acceptance\CurrentLegalSubject;
use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceExemptionInterface;
use Nowo\LegalKitBundle\DependencyInjection\Compiler\DeprecatedPortsPass;
use Nowo\LegalKitBundle\DependencyInjection\Configuration;
use Nowo\LegalKitBundle\Tests\Support\TestExemption;
use Nowo\LegalKitBundle\Tests\Support\TestRequestExemption;
use Nowo\LegalKitBundle\Tests\Support\TestSubjectIdResolver;
use Nowo\LegalKitBundle\Tests\Support\TestSubjectResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function restore_error_handler;
use function set_error_handler;

use const E_USER_DEPRECATED;

/**
 * 1.12 runtime deprecations: host ports typed with framework classes, and `doctrine.private_entity_manager`.
 */
final class DeprecatedPortsPassTest extends TestCase
{
    public function testDeprecatedHostPortsAreReported(): void
    {
        $deprecations = $this->deprecations(function (): void {
            (new DeprecatedPortsPass())->process($this->container(TestSubjectResolver::class, [TestExemption::class, TestRequestExemption::class]));
        });
        self::assertCount(2, $deprecations);
        self::assertStringContainsString('acceptance.subject_resolver "' . TestSubjectResolver::class . '"', $deprecations[0]);
        self::assertStringContainsString('LegalSubjectIdResolverInterface', $deprecations[0]);
        self::assertStringContainsString('exemption voter "' . TestExemption::class . '"', $deprecations[1]);

        self::assertSame([], $this->deprecations(function (): void {
            (new DeprecatedPortsPass())->process($this->container(TestSubjectIdResolver::class, [TestRequestExemption::class]));
            (new DeprecatedPortsPass())->process(new ContainerBuilder());
            $missing = new ContainerBuilder();
            $missing->register(CurrentLegalSubject::class, CurrentLegalSubject::class)->setArgument('$resolver', new Reference('missing'));
            (new DeprecatedPortsPass())->process($missing);
        }));
    }

    public function testThePrivateEntityManagerOptionIsDeprecated(): void
    {
        $deprecations = $this->deprecations(static function (): void {
            (new Processor())->processConfiguration(new Configuration(), [['doctrine' => ['private_entity_manager' => false]]]);
        });
        self::assertCount(1, $deprecations);
        self::assertStringContainsString('"private_entity_manager"', $deprecations[0]);
        self::assertSame([], $this->deprecations(static function (): void {
            (new Processor())->processConfiguration(new Configuration(), [[]]);
        }));
    }

    /**
     * @param list<class-string> $voters
     */
    private function container(string $resolver, array $voters): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('resolver.class', $resolver);
        $container->register('app.subject_resolver', '%resolver.class%');
        $container->register(CurrentLegalSubject::class, CurrentLegalSubject::class)->setArgument('$resolver', new Reference('app.subject_resolver'));
        foreach ($voters as $voter) {
            $container->register($voter, $voter)->addTag(LegalAcceptanceExemptionInterface::TAG);
        }

        return $container;
    }

    /**
     * @return list<string>
     */
    private function deprecations(callable $code): array
    {
        $messages = [];
        // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler (test only, restored in finally)
        set_error_handler(static function (int $level, string $message) use (&$messages): bool {
            $messages[] = $message;

            return true;
        }, E_USER_DEPRECATED);
        try {
            $code();
        } finally {
            restore_error_handler();
        }

        return $messages;
    }
}
