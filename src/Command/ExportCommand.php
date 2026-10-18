<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Command;

use Nowo\LegalKitBundle\Portability\LegalDocumentPortability;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function file_put_contents;
use function is_string;
use function json_encode;
use function sprintf;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Exports the stored operator copies as `legal_documents` JSON (tokens kept), for hosts without an ops-seed.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[AsCommand(name: self::NAME, description: 'Export the stored legal pages as legal_documents JSON')]
final class ExportCommand extends Command
{
    public const string NAME = 'nowo:legal-kit:export';

    /**
     * @param LegalDocumentPortability $portability Export / import
     */
    public function __construct(
        private readonly LegalDocumentPortability $portability,
    ) {
        parent::__construct(self::NAME);
    }

    protected function configure(): void
    {
        $this
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write the JSON here (default: stdout)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows    = $this->portability->export();
        $json    = json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        $outPath = $input->getOption('output');
        if (!is_string($outPath) || $outPath === '') {
            $output->write($json);

            return Command::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);
        if (@file_put_contents($outPath, $json) === false) {
            $io->error(sprintf('Cannot write %s', $outPath));

            return Command::FAILURE;
        }
        $io->success(sprintf('Exported %d legal document(s) to %s', count($rows), $outPath));

        return Command::SUCCESS;
    }
}
