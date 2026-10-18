<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\User;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\LegalKitBundle\Entity\LegalDocument;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The demo end to end on MySQL: public pages, footer, admin sign-in, edit and restore.
 */
final class DemoTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureDatabase((string) $_ENV['DATABASE_URL']);
        $this->client = self::createClient();
        $manager      = self::getContainer()->get(EntityManagerInterface::class);
        $tool         = new SchemaTool($manager);
        $metadata     = $manager->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $user = new User('admin');
        $user->setPassword('unused');
        $manager->persist($user);
        $manager->flush();
    }

    public function testHomeAndPublicPages(): void
    {
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="demo-footer"] [data-nowo-legal-link="privacy"]');

        $this->client->request('GET', '/legal/privacy');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('article h1', 'Privacy policy');
        self::assertStringContainsString('Acme Cloud', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/de/legal/cookies');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('article h1', 'Cookie');
        self::assertSelectorExists('.nowo-legal-cookie-table');

        $this->client->request('GET', '/en/legal/terms');
        self::assertResponseRedirects('/legal/terms', 301);
    }

    public function testAdminRequiresSignIn(): void
    {
        $this->client->request('GET', '/admin/legal');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testAdminEditAndRestore(): void
    {
        $admin = self::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class)->findOneBy(['email' => 'admin']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/admin/legal/notice/es');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="nowo_legal_kit_document"]')->form();
        $form['nowo_legal_kit_document[body]'] = '<h1>Aviso legal</h1><p>Acme Cloud S.L., NIF B00000000</p>';
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/legal/notice/es');

        $this->client->request('GET', '/es/legal/notice');
        self::assertSelectorTextContains('article', 'Acme Cloud S.L.');
        $stored = self::getContainer()->get(EntityManagerInterface::class)->getRepository(LegalDocument::class)->findOneBy(['slug' => 'notice', 'locale' => 'es']);
        self::assertInstanceOf(LegalDocument::class, $stored);
        self::assertNotNull($stored->getUpdatedBy(), 'AuditKit blames the editor');

        $crawler = $this->client->request('GET', '/admin/legal/notice/es');
        $this->client->submit($crawler->filter('form[name="nowo_legal_kit_reset"]')->form());
        self::assertResponseRedirects('/admin/legal/notice/es');
        $this->client->request('GET', '/es/legal/notice');
        self::assertSelectorTextContains('article', 'Nombre legal del operador');
    }

    /**
     * Creates the test database when it does not exist yet (root credentials, test only).
     */
    public static function ensureDatabase(string $url): void
    {
        $params = (new DsnParser(['mysql' => 'pdo_mysql']))->parse($url);
        $name   = (string) ($params['dbname'] ?? '');
        unset($params['dbname']);
        $connection = DriverManager::getConnection($params);
        $connection->executeStatement('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $name) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $connection->close();
    }
}
