<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Unit\Acceptance;

use Nowo\LegalKitBundle\Acceptance\AcceptanceRequestMatcher;
use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceRequestExemptionInterface;
use Nowo\LegalKitBundle\Acceptance\LegalRequestContext;
use Nowo\LegalKitBundle\Catalog\ConfiguredLegalPageCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

use function json_encode;
use function print_r;
use function serialize;
use function unserialize;
use function var_export;

use const JSON_THROW_ON_ERROR;

/**
 * `acceptance.context_cookies` (1.13): only the cookies the host names reach the framework-free exemption voters,
 * never the session cookie, and never through a dump of the context.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class LegalRequestContextCookiesTest extends TestCase
{
    private const string OPT_IN = 'e2e_gate';

    private function request(): Request
    {
        $request = Request::create('/app', cookies: [
            self::OPT_IN => '1',
            'SESSID'     => 'REDACTED-TEST-x',
            'REMEMBERME' => 'REDACTED-TEST-y',
            'nested'     => ['a' => 'b'],
        ]);
        $request->setSession(new Session(new MockArraySessionStorage(name: 'SESSID')));
        $request->attributes->set('_route', 'app_home');

        return $request;
    }

    private function optInVoter(): LegalAcceptanceRequestExemptionInterface
    {
        return new class implements LegalAcceptanceRequestExemptionInterface {
            public function isExemptRequest(LegalRequestContext $request): bool
            {
                return $request->cookie('e2e_gate') !== '1';
            }
        };
    }

    public function testTheDefaultEmptyAllowlistBuildsExactlyThe112Context(): void
    {
        $request = $this->request();
        $context = AcceptanceRequestMatcher::context($request);

        self::assertEquals(AcceptanceRequestMatcher::context($request, []), $context);
        self::assertEquals(new LegalRequestContext('/app', 'GET', 'app_home', [], 'html', false, 'en'), $context);
        foreach ([self::OPT_IN, 'SESSID', 'REMEMBERME', 'nested'] as $name) {
            self::assertNull($context->cookie($name), $name);
            self::assertFalse($context->hasCookie($name), $name);
        }
        self::assertTrue((new AcceptanceRequestMatcher(new ConfiguredLegalPageCatalog([]), [], [], [], [$this->optInVoter()]))->isExempt($request), 'The voter sees no cookie by default.');
    }

    public function testOnlyTheNamedCookiesAreCopiedAndNeverTheSessionCookie(): void
    {
        $context = AcceptanceRequestMatcher::context($this->request(), [self::OPT_IN, 'SESSID', 'nested', 'absent']);

        self::assertSame('1', $context->cookie(self::OPT_IN));
        self::assertTrue($context->hasCookie(self::OPT_IN));
        self::assertNull($context->cookie('SESSID'), 'The session cookie is never copied, even when named.');
        self::assertNull($context->cookie('REMEMBERME'), 'Not named.');
        self::assertNull($context->cookie('nested'), 'String values only.');
        self::assertFalse($context->hasCookie('absent'));
        self::assertNull($context->cookie('E2E_GATE'), 'Exact names.');

        $noSession = Request::create('/app', cookies: ['SESSID' => 'plain']);
        self::assertSame('plain', AcceptanceRequestMatcher::context($noSession, ['SESSID'])->cookie('SESSID'), 'Without a session it is an ordinary cookie the host named.');
    }

    public function testTheVoterReadsTheNamedCookiePerRequest(): void
    {
        $matcher = new AcceptanceRequestMatcher(new ConfiguredLegalPageCatalog([]), [], [], [], [$this->optInVoter()], [self::OPT_IN]);

        self::assertFalse($matcher->isExempt($this->request()), 'Opted in: the gate applies.');
        self::assertTrue($matcher->isExempt(Request::create('/app')), 'Nothing kept from the previous request.');
        self::assertFalse($matcher->isExempt($this->request()));
    }

    public function testCookieValuesNeverShowInADumpAndDoNotSurviveACopy(): void
    {
        $context = AcceptanceRequestMatcher::context(Request::create('/app', cookies: ['theme' => 'REDACTED-TEST-z']), ['theme']);

        $dumper = new CliDumper();
        $dumper->setColors(false);
        $dumps = [
            'var_dumper' => (string) $dumper->dump((new VarCloner())->cloneVar($context), true),
            'var_export' => var_export($context, true),
            'array'      => print_r((array) $context, true),
            'print_r'    => print_r($context, true),
            'json'       => json_encode($context, JSON_THROW_ON_ERROR),
            'serialize'  => serialize($context),
        ];
        foreach ($dumps as $kind => $dump) {
            self::assertStringNotContainsString('REDACTED-TEST-', $dump, $kind);
        }

        $copy = unserialize($dumps['serialize']);
        self::assertInstanceOf(LegalRequestContext::class, $copy);
        self::assertNull($copy->cookie('theme'));
        self::assertNull((clone $context)->cookie('theme'));
        self::assertSame('REDACTED-TEST-z', $context->cookie('theme'));
    }

    /** MU-L-I2: the constructor keeps string values only, whoever builds the context. */
    public function testTheConstructorKeepsStringValuesOnly(): void
    {
        $context = new LegalRequestContext('/app', cookies: ['ok' => '1', 'nested' => ['a' => 'b'], 'number' => 7, 9 => 'numeric-name']);

        self::assertSame('1', $context->cookie('ok'));
        self::assertFalse($context->hasCookie('nested'));
        self::assertFalse($context->hasCookie('number'));
        self::assertSame('numeric-name', $context->cookie('9'));
        self::assertFalse((new LegalRequestContext('/app', cookies: ['nested' => ['x']]))->hasCookie('nested'));
    }
}
