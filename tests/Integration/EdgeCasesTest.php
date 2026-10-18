<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Integration;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\LegalKitBundle\Controller\Admin\LegalDocumentAdminController;
use Nowo\LegalKitBundle\Controller\LegalPageController;
use Nowo\LegalKitBundle\Doctrine\TablePrefixListener;
use Nowo\LegalKitBundle\Entity\LegalDocument;
use Nowo\LegalKitBundle\EventListener\ActionLogListener;
use Nowo\LegalKitBundle\Portability\LegalDocumentPortability;
use Nowo\LegalKitBundle\Repository\LegalDocumentRepository;
use Nowo\LegalKitBundle\Tests\Support\Entity\ActionLog;
use Nowo\LegalKitBundle\Tests\Support\Entity\User;
use Nowo\LegalKitBundle\Tests\Support\KernelTestCase;
use Nowo\LegalKitBundle\Tests\Support\TestKernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Defensive paths: controllers reached without the router, the table prefix listener on its own, repository maps.
 */
final class EdgeCasesTest extends KernelTestCase
{
    public function testPublicControllerWithoutASlugIsNotFound(): void
    {
        $controller = $this->service($this->bootKernel(), LegalPageController::class);

        $this->expectException(NotFoundHttpException::class);
        $controller->show(Request::create('/x'));
    }

    public function testAdminControllerRefusesADisabledLocale(): void
    {
        $controller = $this->service($this->bootKernel(), LegalDocumentAdminController::class);

        $this->expectException(NotFoundHttpException::class);
        $controller->edit(Request::create('/x'), 'notice', 'fr');
    }

    public function testTablePrefixListenerScope(): void
    {
        $kernel  = $this->bootKernel();
        $manager = $this->entityManager($kernel);

        $metadata = new ClassMetadata(LegalDocument::class);
        $metadata->setPrimaryTable(['name' => 'legal_document']);
        (new TablePrefixListener(''))->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $manager));
        self::assertSame('legal_document', $metadata->getTableName(), 'Empty prefix: no-op');

        (new TablePrefixListener('app_'))->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $manager));
        self::assertSame('app_legal_document', $metadata->getTableName(), 'No index definitions: table only');

        $host = new ClassMetadata(User::class);
        $host->setPrimaryTable(['name' => 'user']);
        (new TablePrefixListener('app_'))->loadClassMetadata(new LoadClassMetadataEventArgs($host, $manager));
        self::assertSame('user', $host->getTableName());
    }

    public function testRepositoryMapsAndEditors(): void
    {
        $kernel     = $this->bootKernel();
        $repository = $this->service($kernel, LegalDocumentRepository::class);
        self::assertSame([], $repository->findByLocales([]));

        $manager = $this->entityManager($kernel);
        $editor  = new User('editor@example.com');
        $manager->persist($editor);
        $manager->flush();
        $document = $this->store($kernel, 'terms', 'es', 'T', '<p>x</p>');
        self::assertNotNull($this->find($kernel, 'terms', 'es')?->getId());
        $manager->getConnection()->executeStatement('UPDATE legal_document SET updated_by_id = ?', [$editor->getId()]);
        $manager->clear();

        self::assertSame('editor@example.com', $repository->customMap()['terms']['es']['updated_by']);
        self::assertSame('terms', $document->getSlug());
    }

    public function testImportsAreRecordedInTheActionLog(): void
    {
        $kernel      = $this->bootKernel([], [], true, ['nowo_audit_kit' => ['user_class' => User::class, 'action_log' => ['entity_class' => ActionLog::class]]]);
        $portability = $this->service($kernel, LegalDocumentPortability::class);
        $portability->apply($portability->normalize([['slug' => 'notice', 'locale' => 'en', 'title' => 'N', 'body' => '<p>n</p>']]), null, true);

        $log = $this->entityManager($kernel)->getRepository(ActionLog::class)->findOneBy(['action' => ActionLogListener::ACTION_IMPORTED]);
        self::assertInstanceOf(ActionLog::class, $log);
        self::assertSame(['count' => 1], $log->getContext());
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(TestKernel $kernel, string $id): object
    {
        $service = $this->testContainer($kernel)->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }
}
