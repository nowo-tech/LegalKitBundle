<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Unit\DependencyInjection;

use Nowo\LegalKitBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

/**
 * `sanitizer.heading_anchors` and `public.prose_class`: off by default, and validated at container build.
 */
final class PresentationOptionsConfigurationTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [$config]);
    }

    public function testOffByDefault(): void
    {
        $config = $this->process([]);

        self::assertSame([], $config['sanitizer']['heading_anchors']);
        self::assertNull($config['public']['prose_class']);
        self::assertSame('@NowoLegalKitBundle/acceptance/interstitial.html.twig', $config['acceptance']['template']);
    }

    public function testValidValues(): void
    {
        $config = $this->process([
            'sanitizer' => ['heading_anchors' => ['wiki-files', 'api_studio', 'mfa']],
            'public'    => ['prose_class' => 'nowo-legal-doc--typography md:prose-lg w-1/2'],
        ]);

        self::assertSame(['wiki-files', 'api_studio', 'mfa'], $config['sanitizer']['heading_anchors']);
        self::assertSame('nowo-legal-doc--typography md:prose-lg w-1/2', $config['public']['prose_class']);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidAnchors(): iterable
    {
        yield 'quote' => ['a"b'];
        yield 'space' => ['a b'];
        yield 'trailing newline' => ["a\n"];
        yield 'leading digit' => ['1a'];
        yield 'leading dash' => ['-a'];
        yield 'uppercase' => ['Location'];
        yield 'proto' => ['__proto__'];
        yield 'empty' => [''];
        yield 'too long' => [str_repeat('a', 65)];
        yield 'not a string' => [12];
    }

    #[DataProvider('invalidAnchors')]
    public function testInvalidAnchorIsRefused(mixed $anchor): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->process(['sanitizer' => ['heading_anchors' => [$anchor]]]);
    }

    public function testInvalidAllowedAttributeNameIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->process(['sanitizer' => ['allowed_elements' => ['h2' => ['data-@x']]]]);
    }

    public function testValidAllowedAttributeNames(): void
    {
        $config = $this->process(['sanitizer' => ['allowed_elements' => ['abbr' => ['title', 'data-x', 'aria-label', 'xml:lang', 'TITLE']]]]);

        self::assertSame(['title', 'data-x', 'aria-label', 'xml:lang', 'TITLE'], $config['sanitizer']['allowed_elements']['abbr']);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidProseClasses(): iterable
    {
        yield 'quote' => ['a" onclick="x'];
        yield 'angle' => ['a>b'];
        yield 'empty' => [''];
        yield 'double space' => ['a  b'];
        yield 'newline' => ["a\nb"];
        yield 'trailing newline' => ["a\n"];
        yield 'too long' => [str_repeat('a', 201)];
        yield 'not a string' => [12];
    }

    #[DataProvider('invalidProseClasses')]
    public function testInvalidProseClassIsRefused(mixed $class): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->process(['public' => ['prose_class' => $class]]);
    }
}
