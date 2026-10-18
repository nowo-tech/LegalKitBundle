<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Command;

use InvalidArgumentException;
use JsonException;
use Nowo\LegalKitBundle\Catalog\LegalPageCatalogInterface;
use Nowo\LegalKitBundle\Locale\LegalLocales;
use Nowo\LegalKitBundle\Placeholder\LegalPlaceholderScanner;
use Nowo\LegalKitBundle\Seed\LegalSeed;
use Nowo\LegalKitBundle\Seed\LegalSeedRegistry;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_is_list;
use function array_values;
use function count;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_readable;
use function is_string;
use function json_decode;
use function json_encode;
use function sprintf;
use function str_contains;
use function str_replace;
use function trim;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Builds ops-seed `legal_documents[]` from the seed of every page × locale and a replacements map
 * `{locale: [[from, to], …]}` (locales without an entry use `en`), so a fleet publishes its real identity while the
 * product seed stays generic (REQ-CC-011).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[AsCommand(name: self::NAME, description: 'Render the legal seed, apply string replacements and emit the legal_documents JSON', aliases: [self::LEGACY_ALIAS])]
final class AdaptSeedCommand extends Command
{
    public const string NAME = 'nowo:legal-kit:adapt-seed';

    public const string LEGACY_ALIAS = 'app:ops-seed:adapt-legal';

    /**
     * @param LegalPageCatalogInterface $catalog Enabled pages
     * @param LegalLocales $locales Enabled locales
     * @param LegalSeedRegistry $seeds Seed providers
     * @param LegalPlaceholderScanner $scanner Markers
     */
    public function __construct(
        private readonly LegalPageCatalogInterface $catalog,
        private readonly LegalLocales $locales,
        private readonly LegalSeedRegistry $seeds,
        private readonly LegalPlaceholderScanner $scanner,
    ) {
        parent::__construct(self::NAME);
    }

    protected function configure(): void
    {
        $this
            ->addOption('replacements', null, InputOption::VALUE_REQUIRED, 'JSON file: { "en": [["from", "to"], …], … }')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write the legal_documents JSON here (default: stdout)')
            ->addOption('require-clear-markers', null, InputOption::VALUE_NONE, 'Fail when a placeholder marker remains');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $mapPath = $input->getOption('replacements');
        if (!is_string($mapPath) || $mapPath === '') {
            $io->error('Missing --replacements=/path/to/replacements.json');

            return Command::FAILURE;
        }
        try {
            $replacements = $this->readReplacements($mapPath);
        } catch (InvalidArgumentException|RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $requireClear = (bool) $input->getOption('require-clear-markers');
        $documents    = [];
        foreach ($this->locales->all() as $locale) {
            $pairs = $replacements[$locale] ?? $replacements['en'] ?? [];
            foreach ($this->catalog->slugs() as $slug) {
                $seed = $this->seeds->find($slug, $locale);
                if (!$seed instanceof LegalSeed) {
                    continue;
                }
                $title = $seed->title;
                $body  = $seed->body;
                foreach ($pairs as [$from, $to]) {
                    $title = str_replace($from, $to, $title);
                    $body  = str_replace($from, $to, $body);
                }
                if ($requireClear) {
                    foreach ($this->scanner->markers() as $marker) {
                        if (str_contains($title . $body, $marker)) {
                            $io->error(sprintf('Marker still present: %s/%s → %s', $slug, $locale, $marker));

                            return Command::FAILURE;
                        }
                    }
                }
                $documents[] = ['slug' => $slug, 'locale' => $locale, 'title' => $title, 'body' => $body];
            }
        }

        $json    = json_encode($documents, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        $outPath = $input->getOption('output');
        if (!is_string($outPath) || $outPath === '') {
            $output->write($json);

            return Command::SUCCESS;
        }
        if (@file_put_contents($outPath, $json) === false) {
            $io->error(sprintf('Cannot write %s', $outPath));

            return Command::FAILURE;
        }
        $io->success(sprintf('Wrote %d legal_documents to %s', count($documents), $outPath));

        return Command::SUCCESS;
    }

    /**
     * @throws RuntimeException When the file cannot be read or is not JSON
     * @throws InvalidArgumentException When the structure is invalid
     *
     * @return array<string, list<array{0: string, 1: string}>>
     */
    private function readReplacements(string $path): array
    {
        if (!is_readable($path)) {
            throw new RuntimeException(sprintf('Cannot read %s', $path));
        }
        $raw = (string) file_get_contents($path);
        if (trim($raw) === '') {
            throw new RuntimeException(sprintf('Empty file %s', $path));
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf('Invalid JSON in %s: %s', $path, $e->getMessage()), 0, $e);
        }
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new InvalidArgumentException('The replacements root must be an object keyed by locale.');
        }

        $out = [];
        foreach ($decoded as $locale => $pairs) {
            if (!is_string($locale) || !is_array($pairs)) {
                throw new InvalidArgumentException('Each locale must map to a list of [from, to] pairs.');
            }
            $normalized = [];
            foreach (array_values($pairs) as $i => $pair) {
                if (!is_array($pair) || !isset($pair[0], $pair[1]) || !is_string($pair[0]) || !is_string($pair[1]) || $pair[0] === '') {
                    throw new InvalidArgumentException(sprintf('%s[%d] must be a [from, to] pair of strings.', $locale, $i));
                }
                $normalized[] = [$pair[0], $pair[1]];
            }
            $out[$locale] = $normalized;
        }

        return $out;
    }
}
