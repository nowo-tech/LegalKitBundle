<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceManager;
use Nowo\LegalKitBundle\Acceptance\PendingLegalPage;
use Nowo\LegalKitBundle\Acceptance\StaleLegalOfferException;
use Nowo\LegalKitBundle\Controller\Admin\LegalDocumentAdminController;
use Nowo\LegalKitBundle\Controller\LegalAcceptanceController;
use Nowo\LegalKitBundle\Entity\LegalDocument;
use Nowo\LegalKitBundle\EventSubscriber\LegalKitConflictListener;
use Nowo\LegalKitBundle\Repository\LegalDocumentRepository;
use Nowo\LegalKitBundle\Tests\Support\TestKernel;
use Nowo\LegalKitBundle\Tests\Support\VersioningTestCase;
use Nowo\LegalKitBundle\Versioning\Entity\LegalPageVersion;
use Nowo\LegalKitBundle\Versioning\LegalPageVersions;
use Nowo\TransactionDoctrine\ConflictCode;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

use function array_map;
use function sort;

/**
 * 1.14 (REQ-RUNTIME-003): two kernels, so two database connections, on a real MySQL 8.4 / 8.0, MariaDB 11.4 or
 * PostgreSQL 16 / 17 server (`LEGAL_KIT_TEST_DATABASE_URL`, CI job "Subject ids on database servers"), on the bundle's
 * hot write paths: the acceptance batch and the publication of a version. Acceptances are evidence: a race never
 * records an acceptance against a version other than the one offered and current, nor twice.
 *
 * Every test session sets a 2 s `innodb_lock_wait_timeout` / `lock_timeout`, so a lock wait fails fast instead of
 * hanging. Skipped without a server (SQLite in memory has one connection).
 */
#[Group('database')]
final class AcceptanceRaceDatabaseTest extends VersioningTestCase
{
    private const string SUBJECT = 'u-race';

    /**
     * The same offer accepted on two connections at once (double submit, two tabs): the second insert waits for the
     * first transaction's key, fails fast and answers 409 + Retry-After + CONFLICT_001; once the first commits, the retry
     * records nothing more. One row per page, never two.
     */
    public function testTheSameOfferAcceptedOnTwoConnectionsRecordsOneRowPerPageAndTheLoserAnswersConflict001(): void
    {
        [$first, $second] = $this->kernels();
        $offer            = $this->manager($first)->pendingFor(self::SUBJECT, 'en');
        self::assertCount(2, $offer);

        $this->connection($first)->beginTransaction();
        self::assertSame(2, $this->manager($first)->record(self::SUBJECT, $offer), 'inside the open transaction');

        $failure = $this->failureOf(fn (): int => $this->manager($second)->record(self::SUBJECT, $offer));
        $answer  = $this->answer($second, $failure, LegalAcceptanceController::class . '::accept');
        self::assertSame(409, $answer->getStatusCode());
        self::assertSame(['X-Conflict-Code' => ConflictCode::RETRY_SAFE, 'Retry-After' => '1'], $answer->getHeaders());

        $this->connection($first)->commit();
        self::assertSame(0, $this->manager($second)->record(self::SUBJECT, $offer), 'the retry finds both rows recorded');
        self::assertSame([['privacy', 1], ['terms', 1]], $this->rows($second));
    }

    /**
     * An admin publishes version 2 with a new text while a user accepts the version 1 offer. Before the publication
     * commits, the acceptance records version 1 with the hash of the version 1 text (what was offered, and current when
     * read). Once it commits, the old offer records nothing; the new offer records version 2 with the new text's hash.
     */
    public function testAPublicationRacingAnAcceptanceNeverRecordsAVersionWithAnotherVersionsText(): void
    {
        [$admin, $user] = $this->kernels();
        $this->store($admin, 'terms', 'en', 'Terms', '<p>Version one.</p>');
        $offer   = $this->manager($user)->pendingFor(self::SUBJECT, 'en');
        $oldHash = $this->hashOf($offer, 'terms');

        $this->connection($admin)->beginTransaction();
        $document = $this->documents($admin)->findOneBySlugAndLocale('terms', 'en');
        self::assertInstanceOf(LegalDocument::class, $document);
        $document->setBody('<p>Version two.</p>');
        $this->documents($admin)->save($document);
        $this->versions($admin)->publishVersion('terms', true, LegalPageVersion::SOURCE_EDITOR, [], null, 2);

        self::assertSame(2, $this->manager($user)->record(self::SUBJECT, $offer), 'the publication is not committed: version 1 is current');
        $this->connection($admin)->commit();
        $this->versions($admin)->afterPublish();

        self::assertSame([['privacy', 1], ['terms', 1]], $this->rows($user));
        self::assertSame($oldHash, $this->storedHash($user, 'terms', 1), 'version 1 with the text of version 1');

        $late = $this->manager($user)->pendingFor('u-late', 'en');
        $this->versions($admin)->publishVersion('terms', true, LegalPageVersion::SOURCE_EDITOR, [], null, 3);
        $this->failureOf(fn (): int => $this->manager($user)->record('u-late', $late), StaleLegalOfferException::class);
        self::assertSame([], $this->manager($user)->exportFor('u-late'), 'an offer older than the committed version records nothing');

        $current = $this->manager($user)->pendingFor(self::SUBJECT, 'en');
        self::assertSame(1, $this->manager($user)->record(self::SUBJECT, $current));
        self::assertSame($this->hashOf($current, 'terms'), $this->storedHash($user, 'terms', 3));
        self::assertNotSame($oldHash, $this->hashOf($current, 'terms'));
    }

    /**
     * Two admins publish the same next version number: the second waits for the first's key, answers 409 +
     * CONFLICT_001, and its retry after the commit is refused by the unique key (the editor shows "changed meanwhile";
     * elsewhere on the admin the domain 409 LEGAL_KIT_DUPLICATE_KEY, never a CONFLICT_* code). One version 2 only.
     */
    public function testTwoAdminsPublishingTheSameVersionStoreOneAndTheLoserAnswersConflict001ThenTheDuplicateKey(): void
    {
        [$first, $second] = $this->kernels();
        $admin            = LegalDocumentAdminController::class . '::edit';

        $this->connection($first)->beginTransaction();
        $this->versions($first)->publishVersion('terms', true, LegalPageVersion::SOURCE_EDITOR, [], null, 2);

        $failure = $this->failureOf(fn () => $this->versions($second)->publishVersion('terms', true, LegalPageVersion::SOURCE_EDITOR, [], null, 2));
        self::assertSame(['X-Conflict-Code' => ConflictCode::RETRY_SAFE, 'Retry-After' => '1'], $this->answer($second, $failure, $admin)->getHeaders());

        $this->connection($first)->commit();
        $duplicate = $this->failureOf(fn () => $this->versions($second)->publishVersion('terms', true, LegalPageVersion::SOURCE_EDITOR, [], null, 2), UniqueConstraintViolationException::class);
        $answer    = $this->answer($second, $duplicate, $admin);
        self::assertInstanceOf(ConflictHttpException::class, $answer);
        self::assertSame([LegalKitConflictListener::ERROR_CODE_HEADER => LegalKitConflictListener::DUPLICATE_KEY], $answer->getHeaders());

        self::assertSame(2, $this->versions($second)->currentUncached('terms'));
        self::assertSame(1, (int) $this->connection($second)->fetchOne('SELECT COUNT(*) FROM ' . $this->entityManager($second)->getClassMetadata(LegalPageVersion::class)->getTableName() . " WHERE slug = 'terms'"));
        $this->versions($second)->publishVersion('terms', false, LegalPageVersion::SOURCE_EDITOR);
        self::assertSame(3, $this->versions($second)->currentUncached('terms'), 'the bundle manager of the loser was recovered');
    }

    /**
     * A lock conflict after a commit earlier in the same request answers CONFLICT_002 without Retry-After: the commit is
     * counted by the bundle's commit-tracking middleware on its own connection.
     */
    public function testALockConflictAfterACommitEarlierInTheRequestAnswersConflict002(): void
    {
        [$holder, $request] = $this->kernels();
        $offer              = $this->manager($holder)->pendingFor(self::SUBJECT, 'en');
        $listener           = $this->listener($request);
        $http               = $this->request(LegalAcceptanceController::class . '::accept');
        $listener->onRequest(new RequestEvent($this->createStub(HttpKernelInterface::class), $http, HttpKernelInterface::MAIN_REQUEST));

        $this->connection($holder)->beginTransaction();
        $this->manager($holder)->record(self::SUBJECT, $offer);

        self::assertSame(2, $this->manager($request)->record('u-other', $this->manager($request)->pendingFor('u-other', 'en')), 'committed');
        $failure = $this->failureOf(fn (): int => $this->manager($request)->record(self::SUBJECT, $offer));
        $event   = new ExceptionEvent($this->createStub(HttpKernelInterface::class), $http, HttpKernelInterface::MAIN_REQUEST, $failure);
        $listener->onException($event);
        $answer = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $answer);
        self::assertSame(409, $answer->getStatusCode());
        self::assertSame(['X-Conflict-Code' => ConflictCode::COMMITTED], $answer->getHeaders());

        $this->connection($holder)->rollBack();
        self::assertSame([['privacy', 1], ['terms', 1]], $this->rows($request, 'u-other'));
        self::assertSame([], $this->rows($request), 'the holder rolled back');
    }

    /**
     * @return array{0: TestKernel, 1: TestKernel}
     */
    private function kernels(): array
    {
        if (TestKernel::serverDatabaseUrl() === null) {
            self::markTestSkipped('Needs a database server: set ' . TestKernel::DATABASE_URL_ENV . '.');
        }
        $first  = $this->acceptanceKernel([], ['app_routes', 'server_database']);
        $second = $this->twinKernel($first);
        foreach ([$first, $second] as $kernel) {
            $connection = $this->connection($kernel);
            $platform   = $connection->getDatabasePlatform();
            if ($platform instanceof AbstractMySQLPlatform) {
                $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 2');
            } elseif ($platform instanceof PostgreSQLPlatform) {
                $connection->executeStatement("SET lock_timeout = '2s'");
            }
        }

        return [$first, $second];
    }

    /**
     * @template T of Throwable
     *
     * @param class-string<T> $expected
     *
     * @return T
     */
    private function failureOf(callable $write, string $expected = Throwable::class): Throwable
    {
        try {
            $write();
        } catch (Throwable $failure) {
            self::assertInstanceOf($expected, $failure);

            return $failure;
        }
        self::fail('The write did not fail.');
    }

    private function answer(TestKernel $kernel, Throwable $failure, string $controller): HttpException
    {
        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), $this->request($controller), HttpKernelInterface::MAIN_REQUEST, $failure);
        $this->listener($kernel)->onException($event);
        $answer = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $answer, $failure->getMessage());

        return $answer;
    }

    private function request(string $controller): Request
    {
        $request = Request::create('/legal/accept', 'POST');
        $request->attributes->set('_controller', $controller);

        return $request;
    }

    /**
     * @param list<PendingLegalPage> $offer
     */
    private function hashOf(array $offer, string $slug): string
    {
        foreach ($offer as $page) {
            if ($page->page->slug === $slug) {
                return $page->contentHash;
            }
        }
        self::fail('No ' . $slug . ' in the offer.');
    }

    private function storedHash(TestKernel $kernel, string $slug, int $version): string
    {
        foreach ($this->manager($kernel)->exportFor(self::SUBJECT) as $row) {
            if ($row['page'] === $slug && $row['version'] === $version) {
                return $row['content_hash'];
            }
        }
        self::fail('No acceptance of ' . $slug . ' version ' . $version . '.');
    }

    /**
     * @return list<array{0: string, 1: int}>
     */
    private function rows(TestKernel $kernel, string $subject = self::SUBJECT): array
    {
        $rows = array_map(static fn (array $row): array => [$row['page'], $row['version']], $this->manager($kernel)->exportFor($subject));
        sort($rows);

        return $rows;
    }

    private function connection(TestKernel $kernel): Connection
    {
        return $this->entityManager($kernel)->getConnection();
    }

    private function manager(TestKernel $kernel): LegalAcceptanceManager
    {
        return $this->service($kernel, LegalAcceptanceManager::class);
    }

    private function versions(TestKernel $kernel): LegalPageVersions
    {
        return $this->service($kernel, LegalPageVersions::class);
    }

    private function documents(TestKernel $kernel): LegalDocumentRepository
    {
        return $this->service($kernel, LegalDocumentRepository::class);
    }

    private function listener(TestKernel $kernel): LegalKitConflictListener
    {
        return $this->service($kernel, LegalKitConflictListener::class);
    }
}
