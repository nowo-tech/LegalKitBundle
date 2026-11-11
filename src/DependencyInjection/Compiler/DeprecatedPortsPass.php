<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\DependencyInjection\Compiler;

use Nowo\LegalKitBundle\Acceptance\CurrentLegalSubject;
use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceExemptionInterface;
use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceRequestExemptionInterface;
use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceSubjectResolverInterface;
use Nowo\LegalKitBundle\Acceptance\LegalSubjectIdResolverInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function array_keys;
use function is_a;
use function is_string;
use function trigger_deprecation;

/**
 * Triggers a deprecation (1.12, architecture bar rule 3) when the host wires a port typed with a framework class:
 * an `acceptance.subject_resolver` that implements only {@see LegalAcceptanceSubjectResolverInterface}, or an
 * exemption voter that implements only {@see LegalAcceptanceExemptionInterface}. Both keep working until 2.0.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class DeprecatedPortsPass implements CompilerPassInterface
{
    /** Package name of the deprecations. */
    private const string PACKAGE = 'nowo-tech/legal-kit-bundle';

    /**
     * Checks the subject resolver and the tagged exemption voters.
     *
     * @param ContainerBuilder $container The container being compiled
     *
     * @return void Nothing (side effects only)
     */
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition(CurrentLegalSubject::class)) {
            $resolver = $container->getDefinition(CurrentLegalSubject::class)->getArguments()['$resolver'] ?? null;
            $class    = $resolver instanceof Reference ? $this->classOf($container, (string) $resolver) : null;
            if ($class !== null && is_a($class, LegalAcceptanceSubjectResolverInterface::class, true) && !is_a($class, LegalSubjectIdResolverInterface::class, true)) {
                trigger_deprecation(self::PACKAGE, '1.12', 'The acceptance.subject_resolver "%s" implements "%s", which is deprecated: implement "%s" instead (removed in 2.0).', $class, LegalAcceptanceSubjectResolverInterface::class, LegalSubjectIdResolverInterface::class);
            }
        }
        foreach (array_keys($container->findTaggedServiceIds(LegalAcceptanceExemptionInterface::TAG)) as $id) {
            $class = $this->classOf($container, $id);
            if ($class !== null && !is_a($class, LegalAcceptanceRequestExemptionInterface::class, true)) {
                trigger_deprecation(self::PACKAGE, '1.12', 'The acceptance exemption voter "%s" implements "%s", which is deprecated: implement "%s" instead (removed in 2.0).', $class, LegalAcceptanceExemptionInterface::class, LegalAcceptanceRequestExemptionInterface::class);
            }
        }
    }

    private function classOf(ContainerBuilder $container, string $id): ?string
    {
        if (!$container->has($id)) {
            return null;
        }
        $class = $container->getParameterBag()->resolveValue($container->findDefinition($id)->getClass() ?? $id);

        return is_string($class) ? $class : null;
    }
}
