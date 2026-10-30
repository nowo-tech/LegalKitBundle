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

    $services->set(LegalDocumentRepository::class);

    $services->set('nowo_legal_kit.url_policy', UrlPolicy::class)->autowire(false)->args([null]);
    $services->set('nowo_legal_kit.sanitizer', DomAllowlistSanitizer::class)
        ->autowire(false)
        ->args([service('nowo_legal_kit.url_policy'), param('nowo_legal_kit.sanitizer.allowed_elements'), null]);
    $services->alias(LegalHtmlSanitizerInterface::class, 'nowo_legal_kit.sanitizer');

    $services->set(TwigSeedProvider::class);
    $services->set(LegalSeedRegistry::class)
        ->args([tagged_iterator(LegalSeedProviderInterface::TAG), param('nowo_legal_kit.seed.fallback_locale')]);

    $services->set(BuiltinTokenResolver::class)
        ->args([param('nowo_legal_kit.brand_name'), param('nowo_legal_kit.seed.date')]);
    $services->set(CookieInventoryTokenResolver::class)
        ->autowire(false)
        ->args([
            service('twig'),
            service('Nowo\\CookieConsentBundle\\Config\\CookieInventoryProvider')->nullOnInvalid(),
            service('request_stack')->nullOnInvalid(),
        ]);
    $services->set(LegalTokenRenderer::class)->args([tagged_iterator(LegalTokenResolverInterface::TAG)]);

    $services->set(LegalDocumentResolver::class);

    $services->set(LegalPlaceholderScanner::class)
        ->arg('$builtinMarkers', param('nowo_legal_kit.placeholders.builtin_markers'))
        ->arg('$extraMarkers', param('nowo_legal_kit.placeholders.extra_markers'));

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
            param('nowo_legal_kit.routing.legacy_admin_aliases'),
        ]);

    // Controllers are not autoconfigured (REQ-SF-004 rule 7): on Symfony >= 7.3 a #[Route] class is autoconfigured
    // with the routing.controller tag, and the host's `routing.controllers` resource would then expose the admin
    // routes without the host importing the `nowo_legal_kit` route type (bypassing web_ui.enabled / path_prefix).
    $services->set(LegalPageController::class)
        ->autoconfigure(false)
        ->tag('controller.service_arguments')
        ->public()
        ->arg('$template', param('nowo_legal_kit.web_ui.templates.public_page'))
        ->arg('$layoutTemplate', param('nowo_legal_kit.public.layout_template'))
        ->arg('$defaultLocaleStyle', param('nowo_legal_kit.routing.default_locale_style'))
        ->arg('$maxAge', param('nowo_legal_kit.public.cache.max_age'))
        ->arg('$sharedMaxAge', param('nowo_legal_kit.public.cache.shared_max_age'))
        ->arg('$dispatcher', service('event_dispatcher'));

    $services->set(LegalDocumentAdminController::class)
        ->autoconfigure(false)
        ->tag('controller.service_arguments')
        ->public()
        ->arg('$templates', param('nowo_legal_kit.web_ui.templates'))
        ->arg('$layoutTemplate', param('nowo_legal_kit.web_ui.layout_template'))
        ->arg('$editor', param('nowo_legal_kit.editor.type'))
        ->arg('$ckeditor5Profile', param('nowo_legal_kit.editor.ckeditor5_profile'))
        ->arg('$defaultLocaleStyle', param('nowo_legal_kit.routing.default_locale_style'))
        ->arg('$dispatcher', service('event_dispatcher'));

    $services->set(LegalDocumentType::class);

    $services->set(LegalKitExtension::class)
        ->arg('$defaultLocaleStyle', param('nowo_legal_kit.routing.default_locale_style'))
        ->arg('$webUiEnabled', param('nowo_legal_kit.web_ui.enabled'));

    $services->set(AllowAllLegalKitAccessChecker::class);
    $services->set(AdminAccessSubscriber::class)
        ->args([service(LegalKitAccessCheckerInterface::class)]);

    $services->set(CheckPlaceholdersCommand::class);
    $services->set(AdaptSeedCommand::class);
    $services->set(ExportCommand::class);
    $services->set(ImportCommand::class);
    $services->set(SeedCommand::class)
        ->arg('$materialize', param('nowo_legal_kit.seed.materialize'));
    $services->set(DoctorCommand::class)
        ->arg('$router', service('router'))
        ->arg('$accessMap', service('security.access_map')->nullOnInvalid())
        ->arg('$cookieConsentInstalled', param('nowo_legal_kit.cookie_consent.installed'))
        ->arg('$builtinMarkers', param('nowo_legal_kit.placeholders.builtin_markers'));
};
