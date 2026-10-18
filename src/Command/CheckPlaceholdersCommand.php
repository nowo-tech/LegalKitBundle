<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Command;

use Nowo\LegalKitBundle\Locale\LegalLocales;
use Nowo\LegalKitBundle\Placeholder\LegalPlaceholderScanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function in_array;
use function is_string;
use function json_encode;
use function sprintf;

use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Deploy gate (REQ-CC-012): exit 1 while a published page still contains a seed marker or an unresolved token.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[AsCommand(name: self::NAME, description: 'Fail while published legal pages still contain generic seed placeholders', aliases: [self::LEGACY_ALIAS])]
final class CheckPlaceholdersCommand extends Command
{
    public const string NAME = 'nowo:legal-kit:check-placeholders';

    public const string LEGACY_ALIAS = 'app:legal:check-placeholders';

    /**
     * @param LegalPlaceholderScanner $scanner Placeholder scanner
     * @param LegalLocales $locales Enabled locales
     */
    public function __construct(
        private readonly LegalPlaceholderScanner $scanner,
        private readonly LegalLocales $locales,
    ) {
        parent::__construct(self::NAME);
    }

    protected function configure(): void
    {
        $this
            ->addOption('locale', 'l', InputOption::VALUE_REQUIRED, 'Check only this locale (default: every enabled locale)')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format: table or json', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $locale = $input->getOption('locale');
        $locale = is_string($locale) && $locale !== '' ? $locale : null;
        $format = $input->getOption('format');
        if (!in_array($format, ['table', 'json'], true)) {
            $io->error('--format must be "table" or "json".');

            return Command::INVALID;
        }
        if ($locale !== null && !$this->locales->has($locale)) {
            $io->error(sprintf('Locale "%s" is not enabled.', $locale));

            return Command::INVALID;
        }

        $this->scanner->reset();
        $hits = $this->scanner->findHits($locale);
        if ($format === 'json') {
            $rows = [];
            foreach ($hits as $hit) {
                $rows[] = $hit->toArray();
            }
            $output->writeln((string) json_encode(['hits' => $rows, 'count' => count($rows)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $hits === [] ? Command::SUCCESS : Command::FAILURE;
        }

        if ($hits === []) {
            $io->success('No generic legal placeholders detected.' . ($locale !== null ? sprintf(' (locale=%s)', $locale) : ''));
            $io->note('Counsel review (REQ-CC-010) is still required before processing other people’s personal data.');

            return Command::SUCCESS;
        }

        $io->error(sprintf('%d placeholder hit(s): replace them in the legal pages admin before production.', count($hits)));
        $rows = [];
        foreach ($hits as $hit) {
            $rows[] = [$hit->locale, $hit->slug, $hit->source, $hit->kind, $hit->marker];
        }
        $io->table(['Locale', 'Slug', 'Source', 'Kind', 'Marker'], $rows);
        $io->note('Counsel review (REQ-CC-010) is still required before processing other people’s personal data.');

        return Command::FAILURE;
    }
}
