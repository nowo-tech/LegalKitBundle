<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Routing;

use Nowo\LegalKitBundle\Catalog\LegalPageCatalogInterface;
use Nowo\LegalKitBundle\Controller\Admin\LegalDocumentAdminController;
use Nowo\LegalKitBundle\Controller\LegalPageController;
use Nowo\LegalKitBundle\Locale\LegalLocales;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

use function implode;
use function preg_quote;
use function rtrim;

/**
 * Route loader `type: nowo_legal_kit` (import once in config/routes):
 *
 * - `legal_<slug>`: GET `/{_locale}<path_prefix>/<path>` (the REQ-CC-003 contract: names and URLs stay stable);
 * - unprefixed style: `legal_<slug>_unlocalized` GET `<path_prefix>/<path>` serving the default locale (the twin
 *   RoutingKit's default-locale router recognises); `/{default}/…` answers 301 to it;
 * - prefixed style: `legal_<slug>_bare` GET `<path_prefix>/<path>` redirecting (302) to the default locale;
 * - admin (web_ui.enabled): `nowo_legal_kit_admin_index|edit|reset` under `web_ui.path_prefix`, plus the deprecated
 *   `admin_legal_*` aliases while `routing.legacy_admin_aliases` is true.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class LegalRouteLoader extends Loader
{
    public const string TYPE = 'nowo_legal_kit';

    public const string ADMIN_ROUTE_PREFIX = 'nowo_legal_kit_admin_';

    public const string ADMIN_INDEX = 'nowo_legal_kit_admin_index';

    public const string ADMIN_EDIT = 'nowo_legal_kit_admin_edit';

    public const string ADMIN_RESET = 'nowo_legal_kit_admin_reset';

    public const string BARE_SUFFIX = '_bare';

    /** Request attribute holding the page slug of a public legal route. */
    public const string SLUG_ATTRIBUTE = '_legal_slug';

    /** @var array<string, string> Legacy (1.x copies) admin route name => bundle route name */
    public const array LEGACY_ADMIN_ALIASES = [
        'admin_legal_index' => self::ADMIN_INDEX,
        'admin_legal_edit'  => self::ADMIN_EDIT,
        'admin_legal_reset' => self::ADMIN_RESET,
    ];

    /**
     * @param LegalPageCatalogInterface $catalog Enabled pages
     * @param LegalLocales $locales Enabled locales
     * @param string $pathPrefix `routing.path_prefix`
     * @param string $defaultLocaleStyle `routing.default_locale_style` (unprefixed|prefixed)
     * @param bool $webUiEnabled `web_ui.enabled`
     * @param string $adminPathPrefix `web_ui.path_prefix`
     * @param bool $legacyAdminAliases `routing.legacy_admin_aliases`
     */
    public function __construct(
        private readonly LegalPageCatalogInterface $catalog,
        private readonly LegalLocales $locales,
        private readonly string $pathPrefix = '/legal',
        private readonly string $defaultLocaleStyle = 'unprefixed',
        private readonly bool $webUiEnabled = true,
        private readonly string $adminPathPrefix = '/admin/legal',
        private readonly bool $legacyAdminAliases = true,
    ) {
        parent::__construct();
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $routes  = new RouteCollection();
        $locales = $this->locales->requirement();
        $default = $this->locales->defaultLocale();
        $prefix  = rtrim($this->pathPrefix, '/');

        foreach ($this->catalog->pages() as $page) {
            $path = $prefix . '/' . $page->path;
            $routes->add($page->routeName(), (new Route('/{_locale}' . $path))
                ->setDefaults(['_controller' => LegalPageController::class . '::show', self::SLUG_ATTRIBUTE => $page->slug])
                ->setRequirements(['_locale' => $locales])
                ->setMethods(['GET', 'HEAD']));

            if ($this->defaultLocaleStyle === 'unprefixed') {
                $routes->add($page->unlocalizedRouteName(), (new Route($path))
                    ->setDefaults(['_controller' => LegalPageController::class . '::show', self::SLUG_ATTRIBUTE => $page->slug, '_locale' => $default])
                    ->setMethods(['GET', 'HEAD']));
            } else {
                $routes->add($page->routeName() . self::BARE_SUFFIX, (new Route($path))
                    ->setDefaults(['_controller' => LegalPageController::class . '::bare', self::SLUG_ATTRIBUTE => $page->slug, '_locale' => $default])
                    ->setMethods(['GET', 'HEAD']));
            }
        }

        if ($this->webUiEnabled && $this->catalog->slugs() !== []) {
            $this->addAdminRoutes($routes, $locales);
        }

        return $routes;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === self::TYPE;
    }

    private function addAdminRoutes(RouteCollection $routes, string $locales): void
    {
        $slugs = [];
        foreach ($this->catalog->slugs() as $slug) {
            $slugs[] = preg_quote($slug, '#');
        }
        $requirements = ['slug' => implode('|', $slugs), 'locale' => $locales];
        $admin        = rtrim($this->adminPathPrefix, '/');

        $routes->add(self::ADMIN_INDEX, (new Route($admin))
            ->setDefaults(['_controller' => LegalDocumentAdminController::class . '::index'])
            ->setMethods(['GET', 'HEAD']));
        $routes->add(self::ADMIN_EDIT, (new Route($admin . '/{slug}/{locale}'))
            ->setDefaults(['_controller' => LegalDocumentAdminController::class . '::edit'])
            ->setRequirements($requirements)
            ->setMethods(['GET', 'HEAD', 'POST']));
        $routes->add(self::ADMIN_RESET, (new Route($admin . '/{slug}/{locale}/reset'))
            ->setDefaults(['_controller' => LegalDocumentAdminController::class . '::reset'])
            ->setRequirements($requirements)
            ->setMethods(['POST']));

        if (!$this->legacyAdminAliases) {
            return;
        }
        foreach (self::LEGACY_ADMIN_ALIASES as $alias => $target) {
            $routes->addAlias($alias, $target)->setDeprecated(
                'nowo-tech/legal-kit-bundle',
                '1.0',
                'The "%alias_id%" route alias is deprecated, use "' . $target . '" (removed in 2.0).',
            );
        }
    }
}
