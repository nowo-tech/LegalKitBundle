<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Support;

use Nowo\LegalKitBundle\Entity\LegalDocument;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;

use function array_replace_recursive;
use function is_dir;
use function is_link;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function unlink;

/**
 * Kernels with versioning (and acceptance) on, a fixed snapshot directory cleaned per test, and command testers.
 */
abstract class VersioningTestCase extends KernelTestCase
{
    protected const string SNAPSHOTS = 'nowo-legal-kit-test-snapshots';

    protected function setUp(): void
    {
        parent::setUp();
        self::removeDir(self::snapshotsDir());
    }

    protected function tearDown(): void
    {
        self::removeDir(self::snapshotsDir());
        parent::tearDown();
    }

    protected static function snapshotsDir(): string
    {
        return sys_get_temp_dir() . '/' . self::SNAPSHOTS;
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string> $extraBundles
     * @param array<string, array<string, mixed>> $extensionConfigs
     */
    protected function versioningKernel(array $config = [], array $extraBundles = [], array $extensionConfigs = []): TestKernel
    {
        return $this->bootKernel(array_replace_recursive([
            'versioning' => ['enabled' => true],
            'seed'       => ['snapshots_dir' => self::snapshotsDir()],
            'acceptance' => ['cache_pool' => null],
        ], $config), $extraBundles, true, $extensionConfigs);
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string> $extraBundles
     */
    protected function acceptanceKernel(array $config = [], array $extraBundles = ['app_routes']): TestKernel
    {
        return $this->versioningKernel(array_replace_recursive([
            'acceptance' => ['enabled' => true, 'enforce' => true, 'subject_resolver' => 'test.legal_kit.subject_resolver'],
        ], $config), $extraBundles);
    }

    /**
     * A typed service of the test container.
     *
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    protected function service(TestKernel $kernel, string $id): object
    {
        $service = $this->testContainer($kernel)->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }

    /**
     * A stored row that must exist.
     */
    protected function found(TestKernel $kernel, string $slug, string $locale): LegalDocument
    {
        $document = $this->find($kernel, $slug, $locale);
        self::assertNotNull($document, $slug . '.' . $locale);

        return $document;
    }

    /**
     * Ticks / unticks a checkbox of a crawler form.
     */
    protected static function tick(Form $form, string $name, bool $on = true): void
    {
        $field = $form[$name];
        self::assertInstanceOf(ChoiceFormField::class, $field);
        $on ? $field->tick() : $field->untick();
    }

    /**
     * Selects a radio / select value of a crawler form.
     */
    protected static function choose(Form $form, string $name, string $value): void
    {
        $field = $form[$name];
        self::assertInstanceOf(ChoiceFormField::class, $field);
        $field->select($value);
    }

    protected function tester(TestKernel $kernel, string $name): CommandTester
    {
        return new CommandTester((new Application($kernel))->find($name));
    }

    protected function storeWithBase(TestKernel $kernel, string $slug, string $locale, string $title, string $body, ?string $revision, ?string $hash): LegalDocument
    {
        $document = new LegalDocument($slug, $locale);
        $document->setTitle($title);
        $document->setBody($body);
        $document->setSeedBase($revision, $hash);
        $manager = $this->entityManager($kernel);
        $manager->persist($document);
        $manager->flush();
        $manager->clear();

        return $document;
    }

    protected static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                self::removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
