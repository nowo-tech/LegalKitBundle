<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Integration;

use Nowo\LegalKitBundle\Acceptance\Entity\LegalAcceptance;
use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceManagerInterface;
use Nowo\LegalKitBundle\Blame\LegalActorId;
use Nowo\LegalKitBundle\Document\LegalDocumentResolverInterface;
use Nowo\LegalKitBundle\Entity\LegalDocument;
use Nowo\LegalKitBundle\Portability\LegalDocumentImportInterface;
use Nowo\LegalKitBundle\Tests\Support\DddHost\Account;
use Nowo\LegalKitBundle\Tests\Support\TestKernel;
use Nowo\LegalKitBundle\Tests\Support\VersioningTestCase;
use Nowo\LegalKitBundle\Versioning\Entity\LegalPageVersion;
use Nowo\LegalKitBundle\Versioning\LegalPageVersionsInterface;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

use function dirname;

/**
 * DDD / hexagonal host kernel (1.12, REQ-ARCH-001 "both host kinds are tested"): the host domain is plain PHP mapped
 * in XML ({@see Account}, no bundle base class, trait or interface), blame by actor id (no foreign key to the host),
 * the framework-free twin ports (`LegalSubjectIdResolverInterface`, `LegalAcceptanceRequestExemptionInterface`,
 * `LegalDocumentImportInterface`) and the zero-config private entity manager. The kernel boots, writes and reads
 * through the ports, and the host's unit of work is never flushed by the bundle.
 */
final class DddHostKernelTest extends VersioningTestCase
{
    public function testTheHostWritesAndReadsThroughThePortsWithoutLosingItsUnitOfWork(): void
    {
        $kernel = $this->dddKernel();
        $host   = $this->entityManager($kernel);
        self::assertFalse($host->getClassMetadata(LegalDocument::class)->hasAssociation('createdBy'), 'no relation to a host entity');
        self::assertFalse($host->getClassMetadata(LegalPageVersion::class)->hasAssociation('createdBy'));

        $account = new Account('acc-ops');
        $host->persist($account);

        $import = $this->service($kernel, LegalDocumentImportInterface::class);
        self::assertSame(1, $import->import([['slug' => 'notice', 'locale' => 'en', 'title' => 'Notice', 'body' => '<p>Hello</p>']], new LegalActorId($account->publicId()), true));
        $version = $this->service($kernel, LegalPageVersionsInterface::class)->publishVersion('terms', true, LegalPageVersion::SOURCE_EDITOR, ['en' => 'New terms']);
        self::assertSame(2, $version->version);
        $acceptances = $this->service($kernel, LegalAcceptanceManagerInterface::class);
        self::assertSame(2, $acceptances->record('acc-ops', $acceptances->pendingFor('acc-ops', 'en')));
        self::assertSame([], $acceptances->pendingFor('acc-ops', 'en'));

        self::assertTrue($host->getUnitOfWork()->isScheduledForInsert($account), 'the host change is still pending');
        $connection = $host->getConnection();
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM ddd_account'));
        self::assertSame('acc-ops', $connection->fetchOne("SELECT updated_by FROM legal_document WHERE slug = 'notice'"));
        self::assertSame('Notice', $this->service($kernel, LegalDocumentResolverInterface::class)->resolve('notice', 'en')?->title);

        $host->flush();
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM ddd_account'), 'the host commits its own unit of work');
    }

    public function testTheTwinPortsDriveTheAcceptanceGate(): void
    {
        $kernel = $this->dddKernel();
        $client = $this->adminClient($kernel, 'editor');
        $client->setServerParameter('HTTP_ACCEPT', 'text/html,application/xhtml+xml');

        $client->request('GET', '/app/skip');
        self::assertSame(200, $client->getResponse()->getStatusCode(), 'exempted by the request-context voter');
        $client->request('GET', '/app');
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame('/legal/accept', $client->getResponse()->headers->get('Location'));

        $crawler = $client->request('GET', '/legal/accept');
        $form    = $crawler->filter('form[name="nowo_legal_kit_acceptance"]')->form();
        $box     = $form['nowo_legal_kit_acceptance[accept_terms]'];
        self::assertInstanceOf(ChoiceFormField::class, $box);
        $box->tick();
        $client->submit($form);
        self::assertSame(303, $client->getResponse()->getStatusCode());

        $host = $this->entityManager($kernel);
        $host->clear();
        $subjects = [];
        foreach ($host->getRepository(LegalAcceptance::class)->findAll() as $acceptance) {
            $subjects[] = $acceptance->getSubjectId();
        }
        self::assertSame(['acc-editor', 'acc-editor'], $subjects, 'the subject id of the framework-free resolver');
        $client->request('GET', '/app');
        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    private function dddKernel(): TestKernel
    {
        return $this->versioningKernel([
            'doctrine'   => ['blame' => 'actor_id'],
            'acceptance' => ['enabled' => true, 'enforce' => true, 'subject_resolver' => 'test.legal_kit.subject_id_resolver'],
        ], ['app_routes', 'ddd_ports'], [
            'doctrine' => ['orm' => ['mappings' => ['DddHost' => [
                'type'      => 'xml',
                'is_bundle' => false,
                'dir'       => dirname(__DIR__) . '/Support/DddHost/config',
                'prefix'    => 'Nowo\\LegalKitBundle\\Tests\\Support\\DddHost',
            ]]]],
        ]);
    }
}
