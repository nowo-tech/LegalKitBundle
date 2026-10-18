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
