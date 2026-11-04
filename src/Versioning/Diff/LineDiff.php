<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Versioning\Diff;

use function array_pop;
use function array_reverse;
use function array_slice;
use function count;
use function min;
use function preg_split;

use const PREG_SPLIT_DELIM_CAPTURE;
use const PREG_SPLIT_NO_EMPTY;

/**
 * Line diff (Myers O(ND), common prefix / suffix trimmed first) with a word diff inside each changed line pair.
 * Plain text in, {@see TextDiff} out; bounded so that an admin page can never spend unbounded time or memory.
 *
 * @internal
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class LineDiff
{
    /** Largest number of lines per side. */
    public const int MAX_LINES = 5000;

    /** Largest line edit distance (beyond it: "too different"). */
    public const int MAX_DISTANCE = 600;

    /** Largest number of word tokens per line for the word diff (beyond it the whole line is marked). */
    public const int MAX_WORDS = 2000;

    /** Largest word edit distance inside one line pair. */
    public const int MAX_WORD_DISTANCE = 400;

    /**
     * Diffs two lists of lines.
     *
     * @param list<string> $old Old lines
     * @param list<string> $new New lines
     *
     * @return TextDiff The rows, or a too-large marker
     */
    public static function diff(array $old, array $new): TextDiff
    {
        if (count($old) > self::MAX_LINES || count($new) > self::MAX_LINES) {
            return new TextDiff([], true);
        }
        $script = self::script($old, $new, self::MAX_DISTANCE);
        if ($script === null) {
            return new TextDiff([], true);
        }

        $rows    = [];
        $deleted = [];
        $added   = [];
        foreach ($script as [$op, $text]) {
            if ($op === '-') {
                $deleted[] = $text;

                continue;
            }
            if ($op === '+') {
                $added[] = $text;

                continue;
            }
            self::flush($rows, $deleted, $added);
            $rows[] = new DiffRow(DiffRow::EQUAL, [new DiffSegment($text)], [new DiffSegment($text)]);
        }
        self::flush($rows, $deleted, $added);

        return new TextDiff($rows);
    }

    /**
     * Word diff of one changed line pair.
     *
     * @return array{0: list<DiffSegment>, 1: list<DiffSegment>} Old and new segments
     */
    public static function words(string $old, string $new): array
    {
        $a      = self::tokens($old);
        $b      = self::tokens($new);
        $script = count($a) > self::MAX_WORDS || count($b) > self::MAX_WORDS ? null : self::script($a, $b, self::MAX_WORD_DISTANCE);
        if ($script === null) {
            return [[new DiffSegment($old, true)], [new DiffSegment($new, true)]];
        }

        $left  = [];
        $right = [];
        foreach ($script as [$op, $token]) {
            if ($op !== '+') {
                self::append($left, $token, $op === '-');
            }
            if ($op !== '-') {
                self::append($right, $token, $op === '+');
            }
        }

        return [$left, $right];
    }

    /**
     * Edit script ('=' keep, '-' delete, '+' insert) of two sequences, null when the edit distance exceeds $maxDistance.
     *
     * @param list<string> $a Old sequence
     * @param list<string> $b New sequence
     *
     * @return ?list<array{0: string, 1: string}>
     */
    public static function script(array $a, array $b, int $maxDistance): ?array
    {
        $n      = count($a);
        $m      = count($b);
        $prefix = 0;
        while ($prefix < $n && $prefix < $m && $a[$prefix] === $b[$prefix]) {
            ++$prefix;
        }
        $suffix = 0;
        while ($suffix < $n - $prefix && $suffix < $m - $prefix && $a[$n - 1 - $suffix] === $b[$m - 1 - $suffix]) {
            ++$suffix;
        }

        $middle = self::myers(array_slice($a, $prefix, $n - $prefix - $suffix), array_slice($b, $prefix, $m - $prefix - $suffix), $maxDistance);
        if ($middle === null) {
            return null;
        }
        $script = [];
        for ($i = 0; $i < $prefix; ++$i) {
            $script[] = ['=', $a[$i]];
        }
        foreach ($middle as $step) {
            $script[] = $step;
        }
        for ($i = $n - $suffix; $i < $n; ++$i) {
            $script[] = ['=', $a[$i]];
        }

        return $script;
    }

    /**
     * Myers' greedy forward search with a trace, then the backtrack.
     *
     * @param list<string> $a Old sequence
     * @param list<string> $b New sequence
     *
     * @return ?list<array{0: string, 1: string}>
     */
    private static function myers(array $a, array $b, int $maxDistance): ?array
    {
        $n     = count($a);
        $m     = count($b);
        $limit = min($n + $m, $maxDistance);
        $v     = [1 => 0];
        $trace = [];
        for ($d = 0; $d <= $limit; ++$d) {
            $trace[] = $v;
            for ($k = -$d; $k <= $d; $k += 2) {
                $x = $k === -$d || ($k !== $d && ($v[$k - 1] ?? -1) < ($v[$k + 1] ?? -1)) ? ($v[$k + 1] ?? 0) : ($v[$k - 1] ?? 0) + 1;
                $y = $x - $k;
                while ($x < $n && $y < $m && $a[$x] === $b[$y]) {
                    ++$x;
                    ++$y;
                }
                $v[$k] = $x;
                if ($x >= $n && $y >= $m) {
                    return self::backtrack($trace, $a, $b);
                }
            }
        }

        return null;
    }

    /**
     * @param list<array<int, int>> $trace V before each step
     * @param list<string> $a Old sequence
     * @param list<string> $b New sequence
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function backtrack(array $trace, array $a, array $b): array
    {
        $x        = count($a);
        $y        = count($b);
        $reversed = [];
        for ($d = count($trace) - 1; $d >= 0; --$d) {
            $v     = $trace[$d];
            $k     = $x - $y;
            $prevK = $k === -$d || ($k !== $d && ($v[$k - 1] ?? -1) < ($v[$k + 1] ?? -1)) ? $k + 1 : $k - 1;
            $prevX = $v[$prevK] ?? 0;
            $prevY = $prevX - $prevK;
            while ($x > $prevX && $y > $prevY) {
                $reversed[] = ['=', $a[$x - 1]];
                --$x;
                --$y;
            }
            if ($d > 0) {
                $reversed[] = $x === $prevX ? ['+', $b[$y - 1]] : ['-', $a[$x - 1]];
            }
            $x = $prevX;
            $y = $prevY;
        }

        return array_reverse($reversed);
    }

    /**
     * Turns the pending deleted / added lines into change, delete and insert rows.
     *
     * @param list<DiffRow> $rows
     * @param list<string> $deleted
     * @param list<string> $added
     */
    private static function flush(array &$rows, array &$deleted, array &$added): void
    {
        $pairs = min(count($deleted), count($added));
        for ($i = 0; $i < $pairs; ++$i) {
            [$left, $right] = self::words($deleted[$i], $added[$i]);
            $rows[]         = new DiffRow(DiffRow::CHANGE, $left, $right);
        }
        for ($i = $pairs, $c = count($deleted); $i < $c; ++$i) {
            $rows[] = new DiffRow(DiffRow::DELETE, [new DiffSegment($deleted[$i], true)], []);
        }
        for ($i = $pairs, $c = count($added); $i < $c; ++$i) {
            $rows[] = new DiffRow(DiffRow::INSERT, [], [new DiffSegment($added[$i], true)]);
        }
        $deleted = [];
        $added   = [];
    }

    /**
     * Words and whitespace runs of a line.
     *
     * @return list<string>
     */
    private static function tokens(string $line): array
    {
        $tokens = preg_split('/(\s+)/u', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        return $tokens === false ? [$line] : $tokens;
    }

    /**
     * Appends a token, merging it into the last segment when the changed flag is the same.
     *
     * @param list<DiffSegment> $segments
     */
    private static function append(array &$segments, string $token, bool $changed): void
    {
        $last = array_pop($segments);
        if ($last instanceof DiffSegment && $last->changed === $changed) {
            $segments[] = new DiffSegment($last->text . $token, $changed);

            return;
        }
        if ($last instanceof DiffSegment) {
            $segments[] = $last;
        }
        $segments[] = new DiffSegment($token, $changed);
    }
}
