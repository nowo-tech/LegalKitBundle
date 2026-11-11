<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Integration;

use ArrayObject;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceManager;
use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceManagerInterface;
use Nowo\LegalKitBundle\Blame\ActorIdBlameListener;
use Nowo\LegalKitBundle\Blame\BlameMappingListener;
use Nowo\LegalKitBundle\Entity\LegalDocument;
use Nowo\LegalKitBundle\Event\LegalAcceptedEvent;
use Nowo\LegalKitBundle\Event\LegalDocumentsImportedEvent;
use Nowo\LegalKitBundle\Portability\LegalDocumentPortability;
use Nowo\LegalKitBundle\Repository\LegalDocumentRepository;
use Nowo\LegalKitBundle\Tests\Support\Blame\TestAdminUser;
use Nowo\LegalKitBundle\Tests\Support\Blame\TestCustomerUser;
use Nowo\LegalKitBundle\Tests\Support\Entity\User;
use Nowo\LegalKitBundle\Tests\Support\TestKernel;
use Nowo\LegalKitBundle\Tests\Support\VersioningTestCase;
use Nowo\LegalKitBundle\Versioning\Entity\LegalPageVersion;
use Nowo\LegalKitBundle\Versioning\LegalPageVersions;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;

use function implode;

/**
 * DDD / hexagonal ports (1.8): `doctrine.blame` modes, the acceptance port with after-commit events and the
 * after-commit import event.
 */
final class DddPortsTest extends VersioningTestCase
{
    public function testDefaultBlameKeepsTheAuditKitRelation(): void
    {
        $kernel = $this->versioningKernel();
        $meta   = $this->entityManager($kernel)->getClassMetadata(LegalDocument::class);

        self::assertSame('relation', $kernel->getContainer()->getParameter('nowo_legal_kit.doctrine.blame'));
        self::assertSame(User::class, $meta->getAssociationTargetClass('createdBy'));
        self::assertTrue($this->entityManager($kernel)->getClassMetadata(LegalPageVersion::class)->hasAssociation('updatedBy'));
        self::assertFalse($meta->hasField('createdByActorId'));
        self::assertFalse($this->testContainer($kernel)->has(BlameMappingListener::class));
        self::assertFalse($this->testContainer($kernel)->has(ActorIdBlameListener::class));
    }

    public function testActorIdBlameWithASecurityUserThatIsNoEntity(): void
    {
        $kernel  = $this->versioningKernel(['doctrine' => ['blame' => 'actor_id']]);
        $manager = $this->entityManager($kernel);
        foreach ([LegalDocument::class, LegalPageVersion::class] as $class) {
            $meta = $manager->getClassMetadata($class);
            self::assertFalse($meta->hasAssociation('createdBy'), $class);
            self::assertFalse($meta->hasAssociation('updatedBy'), $class);
            self::assertSame('created_by', $meta->getColumnName('createdByActorId'));
            self::assertSame('updated_by', $meta->getColumnName('updatedByActorId'));
            foreach ($meta->table['indexes'] ?? [] as $index) {
                self::assertNotContains('created_by_id', $index['columns'] ?? []);
            }
        }
        $sql = implode("\n", (new SchemaTool($manager))->getCreateSchemaSql([$manager->getClassMetadata(LegalDocument::class)]));
        self::assertStringContainsString('created_by VARCHAR(191) DEFAULT NULL', $sql);
        self::assertStringNotContainsString('REFERENCES', $sql, 'No foreign key to the host users table');

        $this->signIn($kernel, 'admin');
        $this->store($kernel, 'notice', 'en', 'Notice', '<p>A</p>');
        $stored = $this->find($kernel, 'notice', 'en');
        self::assertNotNull($stored);
        self::assertSame('admin', $stored->getCreatedByActorId(), 'InMemoryUser: no getId(), the user identifier');
        self::assertSame('admin', $stored->getUpdatedByActorId());

        $this->signIn($kernel, 'editor');
        $stored->setTitle('Notice 2');
        $manager->flush();
        $stored = $this->find($kernel, 'notice', 'en');
        self::assertNotNull($stored);
        self::assertSame('admin', $stored->getCreatedByActorId());
        self::assertSame('editor', $stored->getUpdatedByActorId());
        self::assertSame('editor', $this->repository($kernel)->customMap()['notice']['en']['updated_by']);

        $version = $this->service($kernel, LegalPageVersions::class)->publish('terms', true, LegalPageVersion::SOURCE_EDITOR);
        self::assertSame('editor', $version->getCreatedByActorId());

        // Other entities are left alone. CLI (no token): the explicit import actor is kept; an entity actor gives its id.
        $actor = new User('ops@example.com');
        $manager->persist($actor);
        $manager->flush();
        $this->signOut($kernel);
        $this->service($kernel, LegalDocumentPortability::class)->apply([
            ['slug' => 'notice', 'locale' => 'en', 'title' => 'Notice 3', 'body' => '<p>C</p>'],
            ['slug' => 'terms', 'locale' => 'en', 'title' => 'Terms', 'body' => '<p>T</p>'],
        ], $actor, true);
        $notice = $this->find($kernel, 'notice', 'en');
        self::assertNotNull($notice);
        self::assertSame('admin', $notice->getCreatedByActorId());
        self::assertSame((string) $actor->getId(), $notice->getUpdatedByActorId());
        self::assertSame((string) $actor->getId(), $this->find($kernel, 'terms', 'en')?->getCreatedByActorId());
    }

    public function testAnUnrecordableSignedInUserIsNotCreditedWithThePreviousEdit(): void
    {
        $kernel  = $this->versioningKernel(['doctrine' => ['blame' => 'actor_id', 'accepted_user_classes' => [TestCustomerUser::class]]]);
        $manager = $this->entityManager($kernel);

        $this->signInAs($kernel, new TestCustomerUser(5, 'customer'));
        $this->store($kernel, 'notice', 'en', 'Notice', '<p>A</p>');
        self::assertSame('5', $this->find($kernel, 'notice', 'en')?->getUpdatedByActorId());

        // Admin#5 of another identity domain edits: recorded as unknown, not as the customer (JX-L1 / JX-L4).
        $this->signInAs($kernel, new TestAdminUser(5, 'admin'));
        $stored = $this->find($kernel, 'notice', 'en');
        self::assertNotNull($stored);
        $stored->setTitle('Notice 2');
        $manager->flush();
        $edited = $this->find($kernel, 'notice', 'en');
        self::assertNotNull($edited);
        self::assertSame('5', $edited->getCreatedByActorId(), 'The creator stays');
        self::assertNull($edited->getUpdatedByActorId(), 'Not credited to the previous editor');

        // No signed-in user (CLI): the row keeps what it has.
        $this->signInAs($kernel, new TestCustomerUser(5, 'customer'));
        $edited->setTitle('Notice 3');
        $manager->flush();
        $this->signOut($kernel);
        $stored = $this->find($kernel, 'notice', 'en');
        self::assertNotNull($stored);
        $stored->setTitle('Notice 4');
        $manager->flush();
        self::assertSame('5', $this->find($kernel, 'notice', 'en')?->getUpdatedByActorId());
    }

    public function testActorIdBlameWithAHostResolver(): void
    {
        $kernel = $this->versioningKernel(['doctrine' => ['blame' => 'actor_id']], ['actor_resolver']);

        $this->signIn($kernel, 'admin');
        $this->store($kernel, 'notice', 'en', 'Notice', '<p>A</p>');
        self::assertSame('acc-admin', $this->find($kernel, 'notice', 'en')?->getCreatedByActorId());

        $this->signOut($kernel);
        $this->service($kernel, LegalDocumentPortability::class)->apply([['slug' => 'privacy', 'locale' => 'en', 'title' => 'Privacy', 'body' => '<p>P</p>']], new User('ops@example.com'), true);
        self::assertSame('acc-explicit', $this->find($kernel, 'privacy', 'en')?->getCreatedByActorId());
    }

    public function testNoBlameColumns(): void
    {
        $kernel  = $this->versioningKernel(['doctrine' => ['blame' => 'none']]);
        $manager = $this->entityManager($kernel);
        $meta    = $manager->getClassMetadata(LegalDocument::class);
        self::assertFalse($meta->hasAssociation('createdBy'));
        self::assertFalse($meta->hasField('createdByActorId'));
        $sql = implode("\n", (new SchemaTool($manager))->getCreateSchemaSql([$meta]));
        self::assertStringNotContainsString('created_by', $sql);
        self::assertFalse($this->testContainer($kernel)->has(ActorIdBlameListener::class));

        $this->signIn($kernel, 'admin');
        $this->store($kernel, 'notice', 'en', 'Notice', '<p>A</p>');
        self::assertNull($this->find($kernel, 'notice', 'en')?->getCreatedByActorId());
        self::assertNull($this->repository($kernel)->customMap()['notice']['en']['updated_by']);
    }

    public function testAcceptanceEventsAfterTheHostCommit(): void
    {
        $kernel  = $this->acceptanceKernel();
        $manager = $this->service($kernel, LegalAcceptanceManagerInterface::class);
        self::assertInstanceOf(LegalAcceptanceManager::class, $manager, 'Alias of the default implementation');
        $events = new ArrayObject();
        $this->service($kernel, EventDispatcherInterface::class)->addListener(LegalAcceptedEvent::class, static function (LegalAcceptedEvent $event) use ($events): void {
            $events->append($event->subjectId . ':' . $event->slug);
        });
        $connection = $this->entityManager($kernel)->getConnection();

        // Host use case "create account + accept terms" in one transaction.
        $connection->beginTransaction();
        $pending = $manager->pendingFor('u-ann', 'en');
        $batch   = $manager->recordInTransaction('u-ann', $pending);
        self::assertSame(2, $batch->count());
        self::assertSame('u-ann', $batch->subjectId);
        self::assertSame(0, $manager->recordInTransaction('u-ann', $pending)->count(), 'A duplicate rolls back its savepoint only');
        self::assertTrue($connection->isTransactionActive());
        self::assertSame([], $events->getArrayCopy(), 'Nothing dispatched before the commit');
        $connection->commit();
        $manager->afterCommit($batch);
        self::assertSame(['u-ann:privacy', 'u-ann:terms'], $events->getArrayCopy());

        // Rolled back: no row, and the batch is simply dropped.
        $connection->beginTransaction();
        self::assertSame(2, $manager->recordInTransaction('u-bob', $manager->pendingFor('u-bob', 'en'))->count());
        $connection->rollBack();
        self::assertCount(2, $manager->pendingFor('u-bob', 'en'));
        self::assertCount(2, $events);

        // record() outside a transaction: one transaction of its own (1.10), events right after its commit.
        self::assertSame(2, $manager->record('u-bob', $manager->pendingFor('u-bob', 'en')));
        self::assertCount(4, $events);
    }

    public function testImportEventAfterTheHostCommit(): void
    {
        $kernel = $this->versioningKernel();
        $events = new ArrayObject();
        $this->service($kernel, EventDispatcherInterface::class)->addListener(LegalDocumentsImportedEvent::class, static function (LegalDocumentsImportedEvent $event) use ($events): void {
            $events->append($event->documents);
        });
        $portability = $this->service($kernel, LegalDocumentPortability::class);
        $connection  = $this->entityManager($kernel)->getConnection();

        $connection->beginTransaction();
        $rows = $portability->normalize([['slug' => 'notice', 'locale' => 'en', 'title' => 'Notice', 'body' => '<p>A</p>']]);
        $portability->apply($rows);
        $this->entityManager($kernel)->flush();
        $connection->commit();
        self::assertSame([], $events->getArrayCopy());
        $portability->afterCommit($rows);
        self::assertSame([[['slug' => 'notice', 'locale' => 'en']]], $events->getArrayCopy());
    }

    private function signIn(TestKernel $kernel, string $identifier): void
    {
        $user = new InMemoryUser($identifier, null, ['ROLE_ADMIN']);
        $this->tokenStorage($kernel)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function signInAs(TestKernel $kernel, UserInterface $user): void
    {
        $this->tokenStorage($kernel)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function signOut(TestKernel $kernel): void
    {
        $this->tokenStorage($kernel)->setToken(null);
    }

    private function tokenStorage(TestKernel $kernel): TokenStorageInterface
    {
        $storage = $this->testContainer($kernel)->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $storage);

        return $storage;
    }

    private function repository(TestKernel $kernel): LegalDocumentRepository
    {
        $repository = $this->entityManager($kernel)->getRepository(LegalDocument::class);
        self::assertInstanceOf(LegalDocumentRepository::class, $repository);

        return $repository;
    }
}
