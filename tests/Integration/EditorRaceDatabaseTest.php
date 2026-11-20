<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Nowo\LegalKitBundle\Controller\Admin\LegalDocumentAdminController;
use Nowo\LegalKitBundle\Entity\LegalDocument;
use Nowo\LegalKitBundle\EventSubscriber\LegalKitConflictListener;
use Nowo\LegalKitBundle\Repository\LegalDocumentRepository;
use Nowo\LegalKitBundle\Tests\Support\TestKernel;
use Nowo\LegalKitBundle\Tests\Support\VersioningTestCase;
use Nowo\TransactionDoctrine\ConflictCode;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

/**
 * 1.15 (REQ-RUNTIME-003 rule 4): the editor's base-token guard on two connections on a real MySQL / MariaDB /
 * PostgreSQL server (`LEGAL_KIT_TEST_DATABASE_URL`, CI job "Subject ids on database servers"). With the default
 * `versioning.enabled: false` a stale edit page never overwrites another admin's save; the loser gets a domain answer
 * (changed meanwhile), a lock wait gets 409 + CONFLICT_001.
 */
#[Group('database')]
final class EditorRaceDatabaseTest extends VersioningTestCase
{
    /**
     * Two admins open the same text; the first saves, the second saves a stale form: nothing is overwritten and the
     * second admin's managed row gets the stored text back.
     */
    public function testAStaleEditNeverOverwritesTheFirstAdminsSave(): void
    {
        [$first, $second] = $this->kernels();
        $this->documents($first)->save($this->document('terms', 'Terms', '<p>Original</p>'));
        $token = LegalDocumentRepository::token('Terms', '<p>Original</p>');

        $stale = $this->documents($second)->findOneBySlugAndLocale('terms', 'en');
        self::assertInstanceOf(LegalDocument::class, $stale);
        $winner = $this->documents($first)->findOneBySlugAndLocale('terms', 'en');
        self::assertInstanceOf(LegalDocument::class, $winner);
        $winner->setBody('<p>First admin</p>');
        self::assertTrue($this->documents($first)->saveIfCurrent($winner, $token));

        $stale->setBody('<p>Second admin, stale</p>');
        self::assertFalse($this->documents($second)->saveIfCurrent($stale, $token));
        self::assertSame('<p>First admin</p>', $stale->getBody(), 'the refused edit is discarded from the manager');
        self::assertSame('<p>First admin</p>', $this->storedBody($second, 'terms'));

        $fresh = LegalDocumentRepository::token('Terms', '<p>First admin</p>');
        $stale->setBody('<p>Second admin, reloaded</p>');
        self::assertTrue($this->documents($second)->saveIfCurrent($stale, $fresh));
        self::assertSame('<p>Second admin, reloaded</p>', $this->storedBody($first, 'terms'));
    }

    /**
     * While the first admin's save holds the row, the second admin's save waits, fails fast and answers 409 +
     * Retry-After + CONFLICT_001 (nothing committed). Once the first commits, the retry carries a stale token and is
     * refused as a domain answer, not as a conflict.
     */
    public function testASaveWaitingOnTheRowAnswersConflict001ThenTheRetryIsStale(): void
    {
        [$first, $second] = $this->kernels();
        $this->documents($first)->save($this->document('terms', 'Terms', '<p>Original</p>'));
        $token = LegalDocumentRepository::token('Terms', '<p>Original</p>');

        $winner = $this->documents($first)->findOneBySlugAndLocale('terms', 'en');
        self::assertInstanceOf(LegalDocument::class, $winner);
        $this->connection($first)->beginTransaction();
        self::assertSame($token, $this->documents($first)->lockedToken('terms', 'en'));
        $winner->setBody('<p>First admin</p>');
        $this->documents($first)->save($winner);

        $loser = $this->documents($second)->findOneBySlugAndLocale('terms', 'en');
        self::assertInstanceOf(LegalDocument::class, $loser);
        $loser->setBody('<p>Second admin</p>');
        $failure = $this->failureOf(fn (): bool => $this->documents($second)->saveIfCurrent($loser, $token));
        $answer  = $this->answer($second, $failure);
        self::assertSame(409, $answer->getStatusCode());
        self::assertSame(['X-Conflict-Code' => ConflictCode::RETRY_SAFE, 'Retry-After' => '1'], $answer->getHeaders());

        $this->connection($first)->commit();
        $retry = $this->documents($second)->findOneBySlugAndLocale('terms', 'en');
        self::assertInstanceOf(LegalDocument::class, $retry);
        $retry->setBody('<p>Second admin</p>');
        self::assertFalse($this->documents($second)->saveIfCurrent($retry, $token), 'the form was built from the old text');
        self::assertSame('<p>First admin</p>', $this->storedBody($second, 'terms'));
    }

    /**
     * Two admins create the same page text at once: the second insert waits for the first's key (409 +
     * CONFLICT_001), and once the first commits, the same save sees the stored text (domain answer), never two rows.
     */
    public function testTwoAdminsCreatingTheSameTextStoreOneRow(): void
    {
        [$first, $second] = $this->kernels();

        $this->connection($first)->beginTransaction();
        $this->documents($first)->save($this->document('terms', 'First', '<p>First</p>'));

        $loser   = $this->document('terms', 'Second', '<p>Second</p>');
        $failure = $this->failureOf(fn (): bool => $this->documents($second)->saveIfCurrent($loser, LegalDocumentRepository::NEW_TOKEN));
        self::assertSame(['X-Conflict-Code' => ConflictCode::RETRY_SAFE, 'Retry-After' => '1'], $this->answer($second, $failure)->getHeaders());

        $this->connection($first)->commit();
        $retry = $this->document('terms', 'Second', '<p>Second</p>');
        self::assertFalse($this->documents($second)->saveIfCurrent($retry, LegalDocumentRepository::NEW_TOKEN));
        self::assertSame(1, (int) $this->connection($second)->fetchOne('SELECT COUNT(*) FROM ' . $this->entityManager($second)->getClassMetadata(LegalDocument::class)->getTableName() . " WHERE slug = 'terms'"));
        self::assertSame('<p>First</p>', $this->storedBody($second, 'terms'));
    }

    /**
     * A plain insert that loses the unique key (no token check in between, as a host API call would) is a unique
     * violation, which the editor turns into "changed meanwhile".
     */
    public function testAnInsertBehindACommittedRowIsAUniqueViolation(): void
    {
        [$first, $second] = $this->kernels();
        $this->documents($first)->save($this->document('terms', 'First', '<p>First</p>'));

        $this->failureOf(fn () => $this->documents($second)->save($this->document('terms', 'Second', '<p>Second</p>')), UniqueConstraintViolationException::class);
        self::assertSame('<p>First</p>', $this->storedBody($second, 'terms'));
    }

    private function document(string $slug, string $title, string $body): LegalDocument
    {
        $document = new LegalDocument($slug, 'en');
        $document->setTitle($title);
        $document->setBody($body);

        return $document;
    }

    private function storedBody(TestKernel $kernel, string $slug): string
    {
        return (string) $this->connection($kernel)->fetchOne('SELECT body FROM ' . $this->entityManager($kernel)->getClassMetadata(LegalDocument::class)->getTableName() . ' WHERE slug = ?', [$slug]);
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

    private function answer(TestKernel $kernel, Throwable $failure): HttpException
    {
        $request = Request::create('/admin/legal/terms/en', 'POST');
        $request->attributes->set('_controller', LegalDocumentAdminController::class . '::edit');
        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $failure);
        $this->service($kernel, LegalKitConflictListener::class)->onException($event);
        $answer = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $answer, $failure->getMessage());

        return $answer;
    }

    private function connection(TestKernel $kernel): Connection
    {
        return $this->entityManager($kernel)->getConnection();
    }

    private function documents(TestKernel $kernel): LegalDocumentRepository
    {
        return $this->service($kernel, LegalDocumentRepository::class);
    }
}
