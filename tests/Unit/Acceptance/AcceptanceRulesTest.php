<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Unit\Acceptance;

use Nowo\LegalKitBundle\Acceptance\AcceptanceRequestMatcher;
use Nowo\LegalKitBundle\Acceptance\CurrentLegalSubject;
use Nowo\LegalKitBundle\Acceptance\RelativeTargetValidator;
use Nowo\LegalKitBundle\Catalog\ConfiguredLegalPageCatalog;
use Nowo\LegalKitBundle\Tests\Support\TestExemption;
use Nowo\LegalKitBundle\Tests\Support\TestSubjectResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function str_repeat;

/**
 * Target validation, exemptions, the HTML-navigation test and the subject of the token.
 */
final class AcceptanceRulesTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function targets(): iterable
    {
        yield 'path' => ['/app', true];
        yield 'path and query' => ['/app/tasks?page=2#x', true];
        yield 'empty' => ['', false];
        yield 'protocol relative' => ['//evil.example/x', false];
        yield 'backslash' => ['/\\evil.example', false];
        yield 'backslash inside' => ['/a\\b', false];
        yield 'absolute' => ['https://evil.example/', false];
        yield 'scheme' => ['javascript:alert(1)', false];
        yield 'CRLF' => ["/a\r\nLocation: x", false];
        yield 'NUL' => ["/a\0", false];
        yield 'too long' => ['/' . str_repeat('a', RelativeTargetValidator::MAX_LENGTH), false];
        yield 'the interstitial' => ['/legal/accept', false];
        yield 'the localized interstitial' => ['/es/legal/accept/?x=1', false];
    }

    #[DataProvider('targets')]
    public function testTargets(string $target, bool $valid): void
    {
        self::assertSame($valid, (new RelativeTargetValidator('/legal/accept'))->isValid($target));
    }

    public function testExemptions(): void
    {
        $matcher = new AcceptanceRequestMatcher(new ConfiguredLegalPageCatalog(['terms' => ['path' => 'terms']]), ['app_mfa', 'account_*'], ['/api', '/build/'], [new TestExemption()]);
        $request = static function (string $path, ?string $route = null): Request {
            $request = Request::create($path);
            if ($route !== null) {
                $request->attributes->set('_route', $route);
            }

            return $request;
        };

        foreach (['legal_terms', 'legal_terms_unlocalized', 'legal_terms_bare', 'nowo_legal_kit_acceptance', 'nowo_legal_kit_acceptance_unlocalized', 'nowo_legal_kit_admin_edit', '_wdt', '_profiler_home', '_error', '_preview_error', 'app_mfa', 'account_delete'] as $route) {
            self::assertTrue($matcher->isExempt($request('/x', $route)), $route);
        }
        foreach (['/api', '/api/v1/x', '/build/app.js', '/app/mfa'] as $path) {
            self::assertTrue($matcher->isExempt($request($path)), $path);
        }
        foreach (['/apix', '/app', '/buildx'] as $path) {
            self::assertFalse($matcher->isExempt($request($path, 'app_home')), $path);
        }
        self::assertFalse($matcher->isExempt($request('/x', 'legal_privacy')), 'Only enabled pages');
        self::assertFalse($matcher->isExempt($request('/x', 'account')), 'A prefix rule needs the prefix');
        self::assertTrue((new AcceptanceRequestMatcher(new ConfiguredLegalPageCatalog([]), [], ['/']))->isExempt($request('/anything')));
    }

    public function testHtmlNavigation(): void
    {
        $html = static function (array $headers, string $format = 'html'): bool {
            $request = Request::create('/app', server: $headers);
            $request->setRequestFormat($format);

            return AcceptanceRequestMatcher::isHtmlNavigation($request);
        };

        self::assertTrue($html(['HTTP_ACCEPT' => 'text/html,application/xhtml+xml']));
        self::assertFalse($html(['HTTP_ACCEPT' => '']), 'No Accept');
        self::assertFalse($html(['HTTP_ACCEPT' => 'application/json']));
        self::assertFalse($html(['HTTP_ACCEPT' => 'text/html', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']));
        self::assertFalse($html(['HTTP_ACCEPT' => 'text/html', 'HTTP_TURBO_FRAME' => 'main']));
        self::assertFalse($html(['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html']));
        self::assertFalse($html(['HTTP_ACCEPT' => 'text/html'], 'json'));
    }

    public function testCurrentSubject(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{0: string, 1: array<mixed>}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $message, $context];
            }
        };
        $storage = new TokenStorage();
        $subject = new CurrentLegalSubject(new TestSubjectResolver(), $storage, $logger);

        self::assertFalse($subject->isAuthenticated());
        self::assertNull($subject->id());
        self::assertFalse($subject->isImpersonating());

        $storage->setToken(new UsernamePasswordToken(new InMemoryUser('ann', null), 'main'));
        self::assertTrue($subject->isAuthenticated());
        self::assertSame('u-ann', $subject->id());

        $storage->setToken(new UsernamePasswordToken(new InMemoryUser('robot', null), 'main'));
        self::assertNull($subject->id());

        $storage->setToken(new UsernamePasswordToken(new InMemoryUser('bad', null), 'main'));
        self::assertNull($subject->id());
        self::assertSame([['Legal acceptance subject id rejected.', ['bundle' => 'NowoLegalKitBundle', 'action' => 'acceptance_subject_invalid']]], $logger->records, 'The id is never logged');

        $original = new UsernamePasswordToken(new InMemoryUser('admin', null, ['ROLE_ADMIN']), 'main', ['ROLE_ADMIN']);
        $storage->setToken(new SwitchUserToken(new InMemoryUser('ann', null), 'main', [], $original));
        self::assertTrue($subject->isImpersonating());

        self::assertNull((new CurrentLegalSubject(new TestSubjectResolver()))->id(), 'Without SecurityBundle');
    }
}
