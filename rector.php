<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Symfony\CodeQuality\Rector\Class_\EventSubscriberToAttributeRector;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
    ])
    // Machine-wide cap: at most 2 worker processes; generous timeout for slow (WSL2 / CI) hosts.
    ->withParallel(300, 2, 10)
    ->withPhpVersion(PhpVersion::PHP_83)
    ->withComposerBased(symfony: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    )
    ->withSkip([
        // rector 2.8.3 (symfony set): a bundle must not rely on the host app's autoconfiguration for
        // #[AsEventListener]; the subscribers are wired explicitly in services.php and tested via getSubscribedEvents().
        EventSubscriberToAttributeRector::class,
        __DIR__ . '/demo',
        __DIR__ . '/vendor',
    ]);
