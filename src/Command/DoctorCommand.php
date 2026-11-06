<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Command;

use Nowo\LegalKitBundle\Catalog\LegalPageCatalogInterface;
use Nowo\LegalKitBundle\Document\LegalDocumentResolver;
use Nowo\LegalKitBundle\Locale\LegalLocales;
use Nowo\LegalKitBundle\Placeholder\BuiltinMarkers;
use Nowo\LegalKitBundle\Repository\LegalDocumentRepository;
use Nowo\LegalKitBundle\Seed\LegalSeed;
use Nowo\LegalKitBundle\Seed\LegalSeedRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\AccessMapInterface;
use Throwable;

use function count;
use function in_array;
use function sprintf;
use function str_contains;

/**
 * Checks the integration: legal routes exist and are anonymous-readable (no `access_control` role on the public
 * prefix), CookieConsent does not auto-open its modal on them and links its privacy route, and the shipped seed still
 * carries the markers the placeholder gate looks for (REQ-CC-012 CI guard). Exit 1 on errors (`--strict`: also on
 * warnings).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[AsCommand(name: self::NAME, description: 'Check the LegalKit integration (routes, public access, CookieConsent, seed markers)')]
final class DoctorCommand extends Command
{
    public const string NAME = 'nowo:legal-kit:doctor';

    /**
     * @param LegalPageCatalogInterface $catalog Enabled pages
     * @param LegalLocales $locales Enabled locales
     * @param LegalSeedRegistry $seeds Seeds
     * @param RouterInterface $router Router
     * @param AccessMapInterface|null $accessMap Security access map (null without SecurityBundle)
     * @param bool $cookieConsentInstalled CookieConsentBundle registered
     * @param list<string> $disabledRoutes `nowo_cookie_consent.disabled_routes`
     * @param string|null $privacyRoute CookieConsent config privacy_route fallback (1.17+)
     * @param bool $builtinMarkers `placeholders.builtin_markers`
     * @param ?LegalDocumentRepository $documents Stored rows (versioning on: rows without a seed base)
     * @param ?LegalDocumentResolver $resolver Texts refused by the sanitiser
     */
    public function __construct(
        private readonly LegalPageCatalogInterface $catalog,
        private readonly LegalLocales $locales,
        private readonly LegalSeedRegistry $seeds,
        private readonly RouterInterface $router,
        private readonly ?AccessMapInterface $accessMap = null,
        private readonly bool $cookieConsentInstalled = false,
        private readonly array $disabledRoutes = [],
        private readonly ?string $privacyRoute = null,
        private readonly bool $builtinMarkers = true,
        private readonly ?LegalDocumentRepository $documents = null,
        private readonly ?LegalDocumentResolver $resolver = null,
    ) {
        parent::__construct(self::NAME);
    }

    protected function configure(): void
    {
        $this
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Exit 1 on warnings too');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $errors   = [];
        $warnings = [];
        $routes   = $this->router->getRouteCollection();

        foreach ($this->catalog->pages() as $page) {
            $route = $routes->get($page->routeName());
            if (!$route instanceof Route) {
                $errors[] = sprintf('Route "%s" is missing: import the "nowo_legal_kit" route type in config/routes.', $page->routeName());

                continue;
            }
            $path = $this->router->generate($page->routeName(), ['_locale' => $this->locales->defaultLocale()]);
            if ($this->accessMap instanceof AccessMapInterface) {
                [$attributes] = $this->accessMap->getPatterns(Request::create($path));
                if ($attributes !== null && $attributes !== [] && !in_array('PUBLIC_ACCESS', $attributes, true)) {
                    $warnings[] = sprintf('%s is not anonymous-readable (access_control requires %s); add PUBLIC_ACCESS for the legal prefix.', $path, implode(', ', $attributes));
                }
            }
            if ($this->cookieConsentInstalled && !in_array($page->routeName(), $this->disabledRoutes, true)) {
                $warnings[] = sprintf('nowo_cookie_consent.disabled_routes does not contain "%s": the consent modal auto-opens on that page.', $page->routeName());
            }
        }

        if ($this->cookieConsentInstalled && $this->catalog->has('privacy') && $this->privacyRoute !== null && !$routes->get($this->privacyRoute) instanceof Route) {
            $errors[] = sprintf('nowo_cookie_consent.privacy_route "%s" does not exist.', $this->privacyRoute);
        }

        if ($this->builtinMarkers && $this->catalog->has('notice')) {
            foreach ($this->locales->all() as $locale) {
                $seed = $this->seeds->find('notice', $locale);
                if (!$seed instanceof LegalSeed) {
                    $warnings[] = sprintf('No legal notice seed for locale "%s".', $locale);

                    continue;
                }
                $found = false;
                foreach (BuiltinMarkers::MARKERS as $marker) {
                    if (str_contains($seed->body, $marker)) {
                        $found = true;

                        break;
                    }
                }
                if (!$found) {
                    $warnings[] = sprintf('The legal notice seed for "%s" has no built-in marker: set placeholders.builtin_markers: false and add your own extra_markers.', $locale);
                }
            }
        }

        foreach ($this->catalog->pages() as $page) {
            if ($page->slug === 'cookies' && $page->requiresAcceptance()) {
                $warnings[] = 'pages.cookies.acceptance is not "none": cookie consent belongs to CookieConsentBundle, not to an acceptance of the cookie notice.';
            }
        }
        $withoutBase = $this->documents?->countWithoutSeedBase() ?? 0;
        if ($withoutBase > 0) {
            $warnings[] = sprintf('Stored legal copies without a seed base: %d. Run nowo:legal-kit:upgrade-seed --from=<revision> (with a snapshot of that revision).', $withoutBase);
        }

        foreach ($this->catalog->slugs() as $slug) {
            foreach ($this->locales->all() as $locale) {
                try {
                    $sources = $this->resolver?->refusedSources($slug, $locale) ?? [];
                } catch (Throwable $exception) {
                    // No database yet, or a seed that cannot render here: not a fault of the text, so only a warning.
                    $warnings[] = sprintf('The text of "%s" (%s) could not be checked: %s.', $slug, $locale, $exception::class);

                    continue;
                }
                foreach ($sources as $source) {
                    $errors[] = sprintf('The %s text of "%s" (%s) has no text left after the sanitiser (empty, or nested too deeply). Where it applies, the page answers 404 and is not offered for acceptance; fix the text.', $source, $slug, $locale);
                }
            }
        }

        foreach ($errors as $error) {
            $io->error($error);
        }
        foreach ($warnings as $warning) {
            $io->warning($warning);
        }
        if ($errors === [] && $warnings === []) {
            $io->success(sprintf('LegalKit integration OK (%d page(s), %d locale(s)).', count($this->catalog->slugs()), count($this->locales->all())));
        }

        return $errors !== [] || ($warnings !== [] && (bool) $input->getOption('strict')) ? Command::FAILURE : Command::SUCCESS;
    }
}
