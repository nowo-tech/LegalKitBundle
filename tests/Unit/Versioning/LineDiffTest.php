<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Unit\Versioning;

use DateTimeImmutable;
use Nowo\LegalKitBundle\Controller\Admin\LegalDocumentAdminController;
use Nowo\LegalKitBundle\Versioning\Diff\DiffRow;
use Nowo\LegalKitBundle\Versioning\Diff\DiffSegment;
use Nowo\LegalKitBundle\Versioning\Diff\LegalTextProjector;
use Nowo\LegalKitBundle\Versioning\Diff\LineDiff;
use Nowo\LegalKitBundle\Versioning\Diff\TextDiff;
use Nowo\LegalKitBundle\Versioning\Entity\LegalPageVersion;
use PHPUnit\Framework\TestCase;

use function array_fill;
use function array_map;
use function range;
use function str_repeat;

/**
 * Text projection and the bounded line / word diff of the compare page.
 */
final class LineDiffTest extends TestCase
{
    public function testProjection(): void
    {
        $html = '<!-- note --><h2>Data</h2><p>We use <a href="/legal/cookies">cookies</a> and <strong>logs</strong>.<br>Second line</p>'
            . '<ul><li>One</li><li>Two <em>it</em></li><li> </li></ul><table><tr><th>A</th><td>B</td></tr></table>'
            . '<p><img src="/x.png" alt="Logo"> &lt;script&gt;alert(1)&lt;/script&gt; %brand%</p><hr><p>  </p>';

        self::assertSame([
            '## Data',
            'We use cookies (/legal/cookies) and logs.',
            'Second line',
            '• One',
            '• Two it',
            'A | B',
            '[Logo] <script>alert(1)</script> %brand%',
        ], LegalTextProjector::lines($html));
        self::assertSame([], LegalTextProjector::lines('  '));
        self::assertSame(['plain'], LegalTextProjector::lines('plain'));
    }

    public function testEqualAndEmpty(): void
    {
        self::assertTrue(LineDiff::diff(['a', 'b'], ['a', 'b'])->isEqual());
        self::assertTrue(LineDiff::diff([], [])->isEqual());
        self::assertFalse(LineDiff::diff([], ['a'])->isEqual());
        self::assertFalse((new TextDiff([], true))->isEqual());
    }

    public function testRows(): void
    {
        $diff = LineDiff::diff(['keep', 'old words here', 'gone', 'tail'], ['keep', 'new words here', 'tail', 'added']);

        self::assertSame([DiffRow::EQUAL, DiffRow::CHANGE, DiffRow::DELETE, DiffRow::EQUAL, DiffRow::INSERT], array_map(static fn (DiffRow $row): string => $row->op, $diff->rows));
        $change = $diff->rows[1];
        self::assertEquals([new DiffSegment('old', true), new DiffSegment(' words here')], $change->left);
        self::assertEquals([new DiffSegment('new', true), new DiffSegment(' words here')], $change->right);
        self::assertEquals([new DiffSegment('gone', true)], $diff->rows[2]->left);
        self::assertSame([], $diff->rows[2]->right);
        self::assertEquals([new DiffSegment('added', true)], $diff->rows[4]->right);
    }

    public function testScriptMinimalAndBounded(): void
    {
        self::assertSame([['=', 'a'], ['-', 'b'], ['+', 'x'], ['=', 'c']], LineDiff::script(['a', 'b', 'c'], ['a', 'x', 'c'], 10));
        self::assertSame([['+', 'x'], ['=', 'a']], LineDiff::script(['a'], ['x', 'a'], 10));
        self::assertSame([['-', 'a']], LineDiff::script(['a'], [], 10));
        self::assertNull(LineDiff::script(['a', 'b', 'c'], ['x', 'y', 'z'], 2));
    }

    public function testLimits(): void
    {
        self::assertTrue(LineDiff::diff(array_fill(0, LineDiff::MAX_LINES + 1, 'x'), ['x'])->tooLarge);
        $old = array_map(static fn (int $i): string => 'old ' . $i, range(1, LineDiff::MAX_DISTANCE));
        $new = array_map(static fn (int $i): string => 'new ' . $i, range(1, LineDiff::MAX_DISTANCE));
        self::assertTrue(LineDiff::diff($old, $new)->tooLarge);

        [$left, $right] = LineDiff::words(str_repeat('a ', LineDiff::MAX_WORDS), 'b');
        self::assertEquals([new DiffSegment(str_repeat('a ', LineDiff::MAX_WORDS), true)], $left);
        self::assertEquals([new DiffSegment('b', true)], $right);
    }

    public function testPageVersionEntity(): void
    {
        $version = new LegalPageVersion('terms', 2, true, LegalPageVersion::SOURCE_EDITOR, new DateTimeImmutable('2026-11-04 10:00:00'), 'r1', ['en' => 'A', 'es' => 'B']);

        self::assertNull($version->getId());
        self::assertSame(['terms', 2, true, 'editor', 'r1'], [$version->getSlug(), $version->getVersion(), $version->requiresAcceptance(), $version->getSource(), $version->getSeedRevision()]);
        self::assertSame('2026-11-04', $version->getPublishedAt()->format('Y-m-d'));
        $version->setSummary('en', '');
        $version->setSummary('de', 'C');
        self::assertSame(['es' => 'B', 'de' => 'C'], $version->getChangeSummary());
        self::assertNull($version->summaryFor('en'));
    }

    public function testPlainTextSummaries(): void
    {
        self::assertSame('', LegalDocumentAdminController::plainText(null));
        self::assertSame("a b\nc", LegalDocumentAdminController::plainText("  a\u{0000}b\r\nc  "));
    }
}
