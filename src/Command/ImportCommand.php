<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Command;

use InvalidArgumentException;
use JsonException;
use Nowo\LegalKitBundle\Portability\LegalDocumentPortability;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function file_get_contents;
use function is_array;
use function is_readable;
use function is_string;
use function json_decode;
use function sprintf;

/**
 * Imports `legal_documents` JSON (a list, or an ops-seed object with a `legal_documents` key): validates every
 * entry, then upserts sanitised rows in one flush (`--dry-run` validates only).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ImportCommand extends Command
{
    public const string NAME = 'nowo:legal-kit:import';

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
            ->setDescription('Import legal_documents JSON (upsert, sanitised)')
            ->addArgument('file', InputArgument::REQUIRED, 'JSON file: a legal_documents list or an object with a legal_documents key')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate only, write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $file = $input->getArgument('file');
        if (!is_string($file) || !is_readable($file)) {
            $io->error(sprintf('Cannot read %s', is_string($file) ? $file : ''));

            return Command::FAILURE;
        }

        try {
            $decoded = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
            if (is_array($decoded) && isset($decoded['legal_documents'])) {
                $decoded = $decoded['legal_documents'];
            }
            $rows = $this->portability->normalize($decoded);
        } catch (JsonException|InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ((bool) $input->getOption('dry-run')) {
            $io->success(sprintf('%d legal document(s) valid (dry run, nothing written).', count($rows)));

            return Command::SUCCESS;
        }

        $written = $this->portability->apply($rows, null, true);
        $io->success(sprintf('Imported %d legal document(s).', $written));

        return Command::SUCCESS;
    }
}
