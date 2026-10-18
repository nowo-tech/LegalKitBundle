<?php

declare(strict_types=1);

use Nowo\LegalKitBundle\Catalog\ConfiguredLegalPageCatalog;
use Nowo\LegalKitBundle\Catalog\LegalPageCatalogInterface;
use Nowo\LegalKitBundle\Command\AdaptSeedCommand;
use Nowo\LegalKitBundle\Command\CheckPlaceholdersCommand;
use Nowo\LegalKitBundle\Command\DoctorCommand;
use Nowo\LegalKitBundle\Command\ExportCommand;
use Nowo\LegalKitBundle\Command\ImportCommand;
use Nowo\LegalKitBundle\Command\SeedCommand;
use Nowo\LegalKitBundle\Controller\Admin\LegalDocumentAdminController;
use Nowo\LegalKitBundle\Controller\LegalPageController;
use Nowo\LegalKitBundle\Document\LegalDocumentResolver;
use Nowo\LegalKitBundle\EventSubscriber\AdminAccessSubscriber;
use Nowo\LegalKitBundle\Form\LegalDocumentType;
use Nowo\LegalKitBundle\Locale\LegalLocales;
use Nowo\LegalKitBundle\Placeholder\LegalPlaceholderScanner;
use Nowo\LegalKitBundle\Portability\LegalDocumentPortability;
use Nowo\LegalKitBundle\Repository\LegalDocumentRepository;
use Nowo\LegalKitBundle\Routing\LegalRouteLoader;
use Nowo\LegalKitBundle\Sanitizer\DomAllowlistSanitizer;
use Nowo\LegalKitBundle\Sanitizer\LegalHtmlSanitizerInterface;
use Nowo\LegalKitBundle\Sanitizer\UrlPolicy;
use Nowo\LegalKitBundle\Security\AllowAllLegalKitAccessChecker;
use Nowo\LegalKitBundle\Security\LegalKitAccessCheckerInterface;
use Nowo\LegalKitBundle\Seed\LegalSeedProviderInterface;
use Nowo\LegalKitBundle\Seed\LegalSeedRegistry;
use Nowo\LegalKitBundle\Seed\TwigSeedProvider;
use Nowo\LegalKitBundle\Token\BuiltinTokenResolver;
use Nowo\LegalKitBundle\Token\CookieInventoryTokenResolver;
use Nowo\LegalKitBundle\Token\LegalTokenRenderer;
use Nowo\LegalKitBundle\Token\LegalTokenResolverInterface;
use Nowo\LegalKitBundle\Twig\LegalKitExtension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/*
 * Service definitions of NowoLegalKitBundle. Hosts add LegalSeedProviderInterface / LegalTokenResolverInterface
 * services (autoconfigured; priority > -100 wins over the built-in ones) and may alias LegalHtmlSanitizerInterface or
 * LegalPageCatalogInterface to their own implementation.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
            ->private();

    $services->set(LegalLocales::class)
        ->args([param('nowo_legal_kit.locales'), param('nowo_legal_kit.default_locale')]);

    $services->set(ConfiguredLegalPageCatalog::class)->args([param('nowo_legal_kit.pages')]);
    $services->alias(LegalPageCatalogInterface::class, ConfiguredLegalPageCatalog::class);

    $services->set(LegalDocumentRepository::class)->tag('doctrine.repository_service');

    $services->set('nowo_legal_kit.url_policy', UrlPolicy::class)->autowire(false)->args([null]);
    $services->set('nowo_legal_kit.sanitizer', DomAllowlistSanitizer::class)
        ->autowire(false)
        ->args([service('nowo_legal_kit.url_policy'), param('nowo_legal_kit.sanitizer.allowed_elements'), null]);
    $services->alias(LegalHtmlSanitizerInterface::class, 'nowo_legal_kit.sanitizer');

    $services->set(TwigSeedProvider::class)
        ->autoconfigure(false)
        ->tag(LegalSeedProviderInterface::TAG, ['priority' => -100]);
    $services->set(LegalSeedRegistry::class)
        ->args([tagged_iterator(LegalSeedProviderInterface::TAG), param('nowo_legal_kit.seed.fallback_locale')]);

    $services->set(BuiltinTokenResolver::class)
        ->autoconfigure(false)
        ->args([param('nowo_legal_kit.brand_name'), param('nowo_legal_kit.seed.date')])
        ->tag(LegalTokenResolverInterface::TAG, ['priority' => -100]);
    $services->set(CookieInventoryTokenResolver::class)
        ->autoconfigure(false)
        ->autowire(false)
        ->args([
            service('twig'),
            service('Nowo\\CookieConsentBundle\\Config\\CookieInventoryProvider')->nullOnInvalid(),
            service('request_stack')->nullOnInvalid(),
        ])
        ->tag(LegalTokenResolverInterface::TAG, ['priority' => -100]);
    $services->set(LegalTokenRenderer::class)->args([tagged_iterator(LegalTokenResolverInterface::TAG)]);

    $services->set(LegalDocumentResolver::class);

    $services->set(LegalPlaceholderScanner::class)
        ->arg('$builtinMarkers', param('nowo_legal_kit.placeholders.builtin_markers'))
        ->arg('$extraMarkers', param('nowo_legal_kit.placeholders.extra_markers'))
        ->tag('kernel.reset', ['method' => 'reset']);

    $services->set(LegalDocumentPortability::class)
        ->public()
        ->arg('$dispatcher', service('event_dispatcher')->nullOnInvalid());

    $services->set(LegalRouteLoader::class)
        ->args([
            service(LegalPageCatalogInterface::class),
            service(LegalLocales::class),
            param('nowo_legal_kit.routing.path_prefix'),
            param('nowo_legal_kit.routing.default_locale_style'),
            param('nowo_legal_kit.web_ui.enabled'),
            param('nowo_legal_kit.web_ui.path_prefix'),
            param('nowo_legal_kit.routing.legacy_admin_aliases'),
        ])
        ->tag('routing.loader');

    $services->set(LegalPageController::class)
        ->public()
        ->arg('$template', param('nowo_legal_kit.web_ui.templates.public_page'))
        ->arg('$layoutTemplate', param('nowo_legal_kit.public.layout_template'))
        ->arg('$defaultLocaleStyle', param('nowo_legal_kit.routing.default_locale_style'))
        ->arg('$maxAge', param('nowo_legal_kit.public.cache.max_age'))
        ->arg('$sharedMaxAge', param('nowo_legal_kit.public.cache.shared_max_age'))
        ->arg('$dispatcher', service('event_dispatcher'))
        ->tag('controller.service_arguments');

    $services->set(LegalDocumentAdminController::class)
        ->public()
        ->arg('$templates', param('nowo_legal_kit.web_ui.templates'))
        ->arg('$layoutTemplate', param('nowo_legal_kit.web_ui.layout_template'))
        ->arg('$editor', param('nowo_legal_kit.editor.type'))
        ->arg('$ckeditor5Profile', param('nowo_legal_kit.editor.ckeditor5_profile'))
        ->arg('$defaultLocaleStyle', param('nowo_legal_kit.routing.default_locale_style'))
        ->arg('$dispatcher', service('event_dispatcher'))
        ->tag('controller.service_arguments');

    $services->set(LegalDocumentType::class)->tag('form.type');

    $services->set(LegalKitExtension::class)
        ->arg('$defaultLocaleStyle', param('nowo_legal_kit.routing.default_locale_style'))
        ->arg('$webUiEnabled', param('nowo_legal_kit.web_ui.enabled'))
        ->tag('twig.extension');

    $services->set(AllowAllLegalKitAccessChecker::class);
    $services->set(AdminAccessSubscriber::class)
        ->args([service(LegalKitAccessCheckerInterface::class)])
        ->tag('kernel.event_subscriber');

    $services->set(CheckPlaceholdersCommand::class)->autoconfigure(false)->tag('console.command', ['command' => CheckPlaceholdersCommand::NAME]);
    $services->set(AdaptSeedCommand::class)->autoconfigure(false)->tag('console.command', ['command' => AdaptSeedCommand::NAME]);
    $services->set(ExportCommand::class)->autoconfigure(false)->tag('console.command', ['command' => ExportCommand::NAME]);
    $services->set(ImportCommand::class)->autoconfigure(false)->tag('console.command', ['command' => ImportCommand::NAME]);
    $services->set(SeedCommand::class)
        ->autoconfigure(false)
        ->arg('$materialize', param('nowo_legal_kit.seed.materialize'))
        ->tag('console.command', ['command' => SeedCommand::NAME]);
    $services->set(DoctorCommand::class)
        ->autoconfigure(false)
        ->arg('$router', service('router'))
        ->arg('$accessMap', service('security.access_map')->nullOnInvalid())
        ->arg('$cookieConsentInstalled', param('nowo_legal_kit.cookie_consent.installed'))
        ->arg('$builtinMarkers', param('nowo_legal_kit.placeholders.builtin_markers'))
        ->tag('console.command', ['command' => DoctorCommand::NAME]);
};
