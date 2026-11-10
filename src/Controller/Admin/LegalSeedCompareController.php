<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Controller\Admin;

use Nowo\LegalKitBundle\Catalog\LegalPage;
use Nowo\LegalKitBundle\Catalog\LegalPageCatalogInterface;
use Nowo\LegalKitBundle\Document\LegalDocumentResolverInterface;
use Nowo\LegalKitBundle\Entity\LegalDocument;
use Nowo\LegalKitBundle\Event\LegalDocumentRebasedEvent;
use Nowo\LegalKitBundle\Locale\LegalLocales;
use Nowo\LegalKitBundle\Repository\LegalDocumentRepository;
use Nowo\LegalKitBundle\Routing\LegalRouteLoader;
use Nowo\LegalKitBundle\Sanitizer\LegalHtmlSanitizerInterface;
use Nowo\LegalKitBundle\Seed\LegalSeed;
use Nowo\LegalKitBundle\Versioning\Diff\LegalTextProjector;
use Nowo\LegalKitBundle\Versioning\Diff\LineDiff;
use Nowo\LegalKitBundle\Versioning\Diff\TextDiff;
use Nowo\LegalKitBundle\Versioning\LegalSeedTracker;
use Nowo\LegalKitBundle\Versioning\SeedSnapshotStore;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\AreaNav;
use Nowo\UiKitBundle\View\AreaNavItem;
use Nowo\UiKitBundle\View\Crumb;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\ShowPage;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

use function in_array;
use function is_string;

/**
 * Versioning admin (`versioning.enabled`): compare an operator copy with the changed built-in text (text diff,
 * rendered side by side, built-in changes from the snapshot) and "Keep my text". Covered by AdminAccessSubscriber
 * (route prefix and controller class).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class LegalSeedCompareController
{
    /** CSRF token id prefix of "Keep my text". */
    public const string REBASE_CSRF_PREFIX = 'nowo_legal_kit_rebase_';

    /** Compare views. */
    public const array VIEWS = ['changes', 'side', 'builtin'];

    /**
     * @param LegalPageCatalogInterface $catalog Enabled pages
     * @param LegalLocales $locales Enabled locales
     * @param LegalDocumentRepository $documents Stored rows
     * @param LegalDocumentResolverInterface $resolver Public-page rendering
     * @param LegalSeedTracker $tracker Seed base helper
     * @param SeedSnapshotStore $snapshots Frozen seeds
     * @param FormFactoryInterface $forms Form factory (CSRF)
     * @param Environment $twig Twig
     * @param UrlGeneratorInterface $urls Router
     * @param TranslatorInterface $translator Flashes
     * @param EventDispatcherInterface $dispatcher Events
     * @param LegalHtmlSanitizerInterface $sanitizer Sanitizer (stored and snapshot bodies are sanitized again before the diff)
     * @param string $template Compare template
     * @param ?string $layoutTemplate `web_ui.layout_template`
     */
    public function __construct(
        private LegalPageCatalogInterface $catalog,
        private LegalLocales $locales,
        private LegalDocumentRepository $documents,
        private LegalDocumentResolverInterface $resolver,
        private LegalSeedTracker $tracker,
        private SeedSnapshotStore $snapshots,
        private FormFactoryInterface $forms,
        private Environment $twig,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private EventDispatcherInterface $dispatcher,
        private LegalHtmlSanitizerInterface $sanitizer,
        private string $template = '@NowoLegalKitBundle/admin/compare.html.twig',
        private ?string $layoutTemplate = null,
    ) {
    }

    /**
     * Compare page (views: changes, side, builtin).
     *
     * @return Response The page, or a redirect to the editor when there is nothing to compare
     */
    #[Route(LegalDocumentAdminController::PATH_PREFIX . '/{slug}/{locale}/compare', name: LegalRouteLoader::ADMIN_COMPARE, requirements: LegalDocumentAdminController::REQUIREMENTS, methods: ['GET', 'HEAD'])]
    public function compare(Request $request, string $slug, string $locale): Response
    {
        $page     = $this->page($slug, $locale);
        $document = $this->documents->findOneBySlugAndLocale($slug, $locale);
        $seed     = $this->tracker->currentSeed($slug, $locale);
        if (!$document instanceof LegalDocument || !$seed instanceof LegalSeed) {
            return $this->noStore(new RedirectResponse($this->urls->generate(LegalRouteLoader::ADMIN_EDIT, ['slug' => $slug, 'locale' => $locale])));
        }

        $base     = $document->getSeedRevision();
        $snapshot = $base !== null && $base !== $this->tracker->revision() ? $this->snapshots->entry($base, $slug, $locale) : null;
        $view     = $request->query->get('view');
        $view     = is_string($view) && in_array($view, self::VIEWS, true) && ($view !== 'builtin' || $snapshot !== null) ? $view : 'changes';

        $vars = ['view' => $view, 'title_diff' => null, 'body_diff' => null, 'left' => null, 'right' => null];
        if ($view === 'side') {
            $vars['left']  = $this->resolver->render($slug, $locale, $document->getTitle(), $document->getBody(), $document->getUpdatedAt() ?? $document->getCreatedAt());
            $vars['right'] = $this->resolver->render($slug, $locale, $seed->title, $seed->body);
        } else {
            [$oldTitle, $oldBody] = $view === 'builtin' && $snapshot !== null ? [$snapshot['title'], $snapshot['body']] : [$document->getTitle(), $document->getBody()];
            $vars['title_diff']   = LineDiff::diff([$oldTitle], [$seed->title]);
            $vars['body_diff']    = self::bodyDiff($this->sanitizer->sanitize($oldBody), $seed->body);
        }

        $params = ['%page%' => $this->translator->trans($page->labelKey(), [], LegalDocumentAdminController::BUNDLE), '%locale%' => $locale];
        $tabs   = [];
        foreach (self::VIEWS as $tab) {
            if ($tab === 'builtin' && $snapshot === null) {
                continue;
            }
            $tabs[] = new AreaNavItem(
                new Label('admin.compare.tab.' . $tab, LegalDocumentAdminController::BUNDLE),
                $this->urls->generate(LegalRouteLoader::ADMIN_COMPARE, ['slug' => $slug, 'locale' => $locale, 'view' => $tab]),
                $tab === $view,
            );
        }
        $editUrl = $this->urls->generate(LegalRouteLoader::ADMIN_EDIT, ['slug' => $slug, 'locale' => $locale]);
        $show    = new ShowPage(
            new PageHeader(
                new Label('admin.compare.title', LegalDocumentAdminController::BUNDLE, $params),
                new Label('admin.compare.intro', LegalDocumentAdminController::BUNDLE, ['%revision%' => $this->tracker->revision()]),
                actions: [
                    new Action('edit', $this->t('admin.compare.use_new'), $editUrl . '?from_seed=1', variant: 'primary'),
                    new Action(
                        'save',
                        $this->t('admin.compare.keep'),
                        $this->urls->generate(LegalRouteLoader::ADMIN_REBASE, ['slug' => $slug, 'locale' => $locale]),
                        method: 'POST',
                        form: $this->rebaseForm($slug, $locale)->createView(),
                    ),
                ],
                breadcrumbs: [
                    new Crumb($this->t('admin.title'), $this->urls->generate(LegalRouteLoader::ADMIN_INDEX)),
                    new Crumb(new Label('admin.edit_title', LegalDocumentAdminController::BUNDLE, $params), $editUrl),
                    new Crumb($this->t('admin.compare.crumb')),
                ],
                tabs: new AreaNav($tabs, $this->t('admin.compare.tab.label')),
            ),
            bundle: LegalDocumentAdminController::BUNDLE,
            layout: $this->layoutTemplate,
            attributes: ['data-testid' => 'nowo-legal-kit-admin-compare'],
        );

        return $this->noStore(new Response($this->twig->render($this->template, ['page' => $show, 'legal_slug' => $slug, 'legal_locale' => $locale] + $vars)));
    }

    /**
     * "Keep my text": bases the copy on the current built-in text (no text change, no version).
     *
     * @return RedirectResponse Back to the editor
     */
    #[Route(LegalDocumentAdminController::PATH_PREFIX . '/{slug}/{locale}/rebase', name: LegalRouteLoader::ADMIN_REBASE, requirements: LegalDocumentAdminController::REQUIREMENTS, methods: ['POST'])]
    public function rebase(Request $request, string $slug, string $locale): RedirectResponse
    {
        $this->page($slug, $locale);
        $form = $this->rebaseForm($slug, $locale);
        $form->handleRequest($request);
        $edit = $this->noStore(new RedirectResponse($this->urls->generate(LegalRouteLoader::ADMIN_EDIT, ['slug' => $slug, 'locale' => $locale])));
        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->flash($request, 'error', 'flash.invalid_csrf');

            return $edit;
        }
        $document = $this->documents->findOneBySlugAndLocale($slug, $locale);
        if ($document instanceof LegalDocument) {
            $this->tracker->rebase($document);
            $this->documents->save($document);
            $this->dispatcher->dispatch(new LegalDocumentRebasedEvent($slug, $locale, $this->tracker->revision()));
            $this->flash($request, 'success', 'flash.rebased');
        }

        return $edit;
    }

    /**
     * Text diff of two sanitized bodies (line projection; never an HTML diff).
     *
     * @return TextDiff The diff
     */
    public static function bodyDiff(string $old, string $new): TextDiff
    {
        return LineDiff::diff(LegalTextProjector::lines($old), LegalTextProjector::lines($new));
    }

    /**
     * @return FormInterface<mixed>
     */
    private function rebaseForm(string $slug, string $locale): FormInterface
    {
        return $this->forms->createNamedBuilder('nowo_legal_kit_rebase', FormType::class, null, [
            'csrf_token_id' => self::REBASE_CSRF_PREFIX . $slug . '_' . $locale,
            'action'        => $this->urls->generate(LegalRouteLoader::ADMIN_REBASE, ['slug' => $slug, 'locale' => $locale]),
            'method'        => 'POST',
        ])->getForm();
    }

    private function page(string $slug, string $locale): LegalPage
    {
        if (!$this->catalog->has($slug) || !$this->locales->has($locale)) {
            throw new NotFoundHttpException('Unknown legal page or locale.');
        }

        return $this->catalog->page($slug);
    }

    private function flash(Request $request, string $type, string $key): void
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $this->translator->trans($key, [], LegalDocumentAdminController::BUNDLE));
        }
    }

    /**
     * @template T of Response
     *
     * @param T $response
     *
     * @return T
     */
    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function t(string $key): Label
    {
        return new Label($key, LegalDocumentAdminController::BUNDLE);
    }
}
