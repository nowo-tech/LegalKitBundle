<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Header;

use Nowo\Brand\Contract\Brand;
use Nowo\Brand\Contract\BrandAsset;
use Nowo\Brand\Contract\BrandAssetSlot;
use Nowo\LegalKitBundle\Brand\LegalBrand;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function preg_match;
use function str_starts_with;
use function strlen;

/**
 * The optional header of the public legal pages (MUX-150, LegalKit 1.7): the brand (name, or the brand provider's
 * light logo as an `<img>`) linked to the home page, and a sign-in link. Off by default (`public.header.enabled`).
 *
 * Link targets (`public.header.home`, `public.header.sign_in`) are route names or URLs, validated by
 * {@see self::isValidTarget()} when the configuration is loaded: a root-relative path (not `//`), an `http(s)://`
 * URL, or a route name. Any other scheme (`javascript:`, `data:`…) is refused there. A route that does not exist at
 * run time is logged and its link is left out: a legal page never fails because of its header.
 *
 * The sign-in link is left out for a signed-in user (a token with a user in the token storage, when SecurityBundle is
 * installed).
 *
 * Stateless: every render asks the brand provider again ({@see LegalBrand}), so a long-running worker keeps nothing.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class LegalPublicHeader
{
    /** A route name: letters, digits, `_`, `.` and `-` (no `/`, `:` or whitespace, so it is never read as a URL). */
    public const string ROUTE_PATTERN = '/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$/D';

    /** An absolute http(s) URL in printable ASCII without quotes, angle brackets, backslashes or backticks. */
    public const string URL_PATTERN = '#^https?://[A-Za-z0-9.-]+(?::[0-9]{1,5})?(?:[/?\#][\x21-\x7e]*)?$#D';

    /** A root-relative path (not protocol-relative `//`) in printable ASCII. */
    public const string PATH_PATTERN = '#^/(?![/\\\\])[\x21-\x7e]*$#D';

    /** Characters never accepted in a link target (attribute breakers). */
    private const string FORBIDDEN = '/["\'<>\\\\`]/';

    /**
     * @param UrlGeneratorInterface $urls URL generator (route-name targets)
     * @param bool $enabled `public.header.enabled`
     * @param string $brandName `brand_name` (the name without a brand provider)
     * @param bool $logo `public.header.logo`: print the provider's light logo when it has one
     * @param string|null $home `public.header.home` (route name or URL; null = the brand is not a link)
     * @param string|null $signIn `public.header.sign_in` (route name or URL; null = no sign-in link)
     * @param LegalBrand|null $brand Brand provider consumer (null with `brand.mode: off` or without brand-contracts)
     * @param LoggerInterface|null $logger Logger for unknown routes
     * @param TokenStorageInterface|null $tokens Security token storage: no sign-in link for a signed-in user
     */
    public function __construct(
        private UrlGeneratorInterface $urls,
        private bool $enabled = false,
        private string $brandName = '',
        private bool $logo = true,
        private ?string $home = '/',
        private ?string $signIn = null,
        private ?LegalBrand $brand = null,
        private ?LoggerInterface $logger = null,
        private ?TokenStorageInterface $tokens = null,
    ) {
    }

    /**
     * Whether a configured link target is accepted: a root-relative path, an http(s) URL or a route name.
     *
     * @param string $target The configured value
     *
     * @return bool Whether it is valid
     */
    public static function isValidTarget(string $target): bool
    {
        if ($target === '' || strlen($target) > 2048 || preg_match(self::FORBIDDEN, $target) === 1) {
            return false;
        }

        return preg_match(self::PATH_PATTERN, $target) === 1
            || preg_match(self::URL_PATTERN, $target) === 1
            || preg_match(self::ROUTE_PATTERN, $target) === 1;
    }

    /**
     * The header view, or null when the header is off.
     *
     * @return array{brand: string, logo: string|null, home: string|null, sign_in: string|null}|null The view (plain
     *                                                                                               values; Twig escapes them)
     */
    public function view(): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        $current = $this->brand?->current();
        $name    = $current instanceof Brand ? $current->name : $this->brandName;

        return [
            'brand'   => $name,
            'logo'    => $this->logo && $current instanceof Brand ? $this->logoUrl($current) : null,
            'home'    => $this->href($this->home, 'home'),
            'sign_in' => $this->signedIn() ? null : $this->href($this->signIn, 'sign_in'),
        ];
    }

    private function logoUrl(Brand $brand): ?string
    {
        $asset = $brand->asset(BrandAssetSlot::LOGO_LIGHT);

        // The contract already refuses unsafe URLs; checked again because the URL goes into an attribute.
        return $asset instanceof BrandAsset && BrandAsset::isSafeUrl($asset->url) ? $asset->url : null;
    }

    private function signedIn(): bool
    {
        return $this->tokens?->getToken()?->getUser() instanceof UserInterface;
    }

    private function href(?string $target, string $key): ?string
    {
        if ($target === null || !self::isValidTarget($target)) {
            return null;
        }
        if (str_starts_with($target, '/') || preg_match(self::URL_PATTERN, $target) === 1) {
            return $target;
        }

        try {
            return $this->urls->generate($target);
        } catch (RoutingException $e) {
            $this->logger?->warning('LegalKitBundle: public.header.{key} route "{route}" cannot be generated; the link is left out.', [
                'key'       => $key,
                'route'     => $target,
                'exception' => $e,
            ]);

            return null;
        }
    }
}
