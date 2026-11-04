<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Unit\DependencyInjection;

use Nowo\LegalKitBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = $this->process([]);

        self::assertSame(['en'], $config['locales']);
        self::assertNull($config['default_locale']);
        self::assertSame(['notice', 'privacy', 'terms', 'cookies'], array_keys($config['pages']));
        self::assertSame('privacy', $config['pages']['privacy']['path']);
        self::assertSame('/legal', $config['routing']['path_prefix']);
        self::assertSame('unprefixed', $config['routing']['default_locale_style']);
        self::assertTrue($config['routing']['legacy_admin_aliases']);
        self::assertFalse($config['seed']['materialize']);
        self::assertSame('auto', $config['editor']['type']);
        self::assertSame('legal', $config['editor']['ckeditor5_profile']);
        self::assertTrue($config['web_ui']['enabled']);
        self::assertSame('/admin/legal', $config['web_ui']['path_prefix']);
        self::assertSame(['ROLE_ADMIN'], $config['security']['access_roles']);
        self::assertFalse($config['security']['allow_unauthenticated']);
        self::assertNull($config['security']['access_checker']);
        self::assertSame(0, $config['public']['cache']['shared_max_age']);
        self::assertTrue($config['console']['legacy_aliases']);
        self::assertSame('', $config['doctrine']['table_prefix']);
        self::assertSame([null, null, null, null], array_column($config['pages'], 'acceptance'), 'Defaults are resolved by the catalogue');
        self::assertNull($config['seed']['revision']);
        self::assertFalse($config['versioning']['enabled']);
        self::assertFalse($config['acceptance']['enabled']);
        self::assertSame('pass', $config['acceptance']['non_html']);
        self::assertSame('cache.app', $config['acceptance']['cache_pool']);
    }

    public function testAcceptanceSetup(): void
    {
        $config = $this->process([
            'versioning' => ['enabled' => true],
            'acceptance' => ['enabled' => true, 'enforce' => true, 'subject_resolver' => 'app.subjects', 'exempt_paths' => ['/api', '/build/'], 'exempt_routes' => ['app_mfa_*']],
            'pages'      => ['privacy' => ['acceptance' => 'none'], 'extra' => ['acceptance' => 'accept']],
            'seed'       => ['revision' => '2026-11-04.1'],
        ]);

        self::assertSame('none', $config['pages']['privacy']['acceptance']);
        self::assertSame('accept', $config['pages']['extra']['acceptance']);
        self::assertSame('2026-11-04.1', $config['seed']['revision']);
    }

    public function testPagesAndLocalesAsParameterString(): void
    {
        $config = $this->process(['locales' => '%app.locales%', 'pages' => ['notice' => [], 'accessibility' => ['path' => 'a11y']]]);

        self::assertSame('%app.locales%', $config['locales']);
        self::assertSame('notice', $config['pages']['notice']['path']);
        self::assertSame('a11y', $config['pages']['accessibility']['path']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidConfigs(): iterable
    {
        yield 'locales type' => [['locales' => 12]];
        yield 'slug' => [['pages' => ['Bad Slug' => []]]];
        yield 'path' => [['pages' => ['notice' => ['path' => '../x']]]];
        yield 'path prefix' => [['routing' => ['path_prefix' => 'legal']]];
        yield 'style' => [['routing' => ['default_locale_style' => 'both']]];
        yield 'seed date' => [['seed' => ['date' => 'yesterday']]];
        yield 'editor' => [['editor' => ['type' => 'tiptap']]];
        yield 'admin prefix' => [['web_ui' => ['path_prefix' => '/']]];
        yield 'seed revision' => [['seed' => ['revision' => '../x']]];
        yield 'enforce without acceptance' => [['acceptance' => ['enforce' => true]]];
        yield 'acceptance without versioning' => [['acceptance' => ['enabled' => true, 'subject_resolver' => 'x']]];
        yield 'acceptance without resolver' => [['versioning' => ['enabled' => true], 'acceptance' => ['enabled' => true]]];
        yield 'page path accept' => [['versioning' => ['enabled' => true], 'acceptance' => ['enabled' => true, 'subject_resolver' => 'x'], 'pages' => ['extra' => ['path' => 'accept']]]];
        yield 'privacy accepted' => [['pages' => ['privacy' => ['acceptance' => 'accept']]]];
        yield 'acceptance mode' => [['pages' => ['terms' => ['acceptance' => 'consent']]]];
        yield 'exempt path wildcard' => [['acceptance' => ['exempt_paths' => ['/api/*']]]];
        yield 'exempt path traversal' => [['acceptance' => ['exempt_paths' => ['/api/../x']]]];
        yield 'exempt path relative' => [['acceptance' => ['exempt_paths' => ['api']]]];
        yield 'exempt route' => [['acceptance' => ['exempt_routes' => ['bad route']]]];
        yield 'non html' => [['acceptance' => ['non_html' => 'block']]];
        yield 'table prefix' => [['doctrine' => ['table_prefix' => 'App-']]];
        yield 'cache' => [['public' => ['cache' => ['max_age' => -1]]]];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidConfigs')]
    public function testInvalidConfiguration(array $config): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->process($config);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [$config]);
    }
}
