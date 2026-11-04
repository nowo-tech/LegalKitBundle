<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Command;

use Nowo\LegalKitBundle\DependencyInjection\Configuration;
use Nowo\LegalKitBundle\Versioning\LegalSeedUpgrader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_filter;
use function array_keys;
use function array_map;
use function count;
use function implode;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * Refreshes the stored legal copies that still equal the seed they started from, flags the edited ones, and
 * optionally publishes a new version per changed page (versioning). Run it as a deploy step, before traffic.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[AsCommand(name: self::NAME, description: 'Refresh unedited legal copies to the new built-in texts and flag edited ones (versioning)')]
final class UpgradeSeedCommand extends Command
{
    public const string NAME = 'nowo:legal-kit:upgrade-seed';

    /** Exit code of --fail-on-outdated when a copy is outdated. */
    public const int EXIT_OUTDATED = 2;

    /**
     * @param LegalSeedUpgrader $upgrader The upgrade
     */
    public function __construct(
        private readonly LegalSeedUpgrader $upgrader,
    ) {
        parent::__construct(self::NAME);
    }

    /**
     * Options: --from, --dry-run, --new-version, --requires-acceptance, --fail-on-outdated.
     *
     * @return void Nothing (side effects only)
     */
    protected function configure(): void
    {
        $this
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Seed revision of copies without a recorded base (their snapshot is read from seed.snapshots_dir)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the result and roll back')
            ->addOption('new-version', null, InputOption::VALUE_NONE, 'Publish one new version per changed page (no acceptance)')
            ->addOption('requires-acceptance', null, InputOption::VALUE_NONE, 'Publish one new version per changed page; users accept / acknowledge it (pages whose mode is not none)')
            ->addOption('fail-on-outdated', null, InputOption::VALUE_NONE, 'Exit with code 2 when an edited copy is outdated');
    }

    /**
     * Runs the upgrade and prints the table.
     *
     * @return int Exit code
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $from = $input->getOption('from');
        $from = is_string($from) && $from !== '' ? $from : null;
        if ($from !== null && preg_match(Configuration::REVISION_PATTERN, $from) !== 1) {
            $io->error(sprintf('Invalid --from revision "%s".', $from));

            return Command::INVALID;
        }
        $missing = $this->upgrader->rowsWithoutBase();
        if ($from === null && $missing !== []) {
            $io->error(sprintf('Stored copies without a seed base: %d. Pass --from=<revision of the texts they were created from> (a snapshot of it must exist).', count($missing)));
            $io->listing(array_map(static fn (array $row): string => $row['slug'] . ' (' . $row['locale'] . ')', $missing));

            return Command::FAILURE;
        }

        $mode   = (bool) $input->getOption('requires-acceptance') ? LegalSeedUpgrader::VERSIONS_ACCEPTANCE : ((bool) $input->getOption('new-version') ? LegalSeedUpgrader::VERSIONS_NEW : LegalSeedUpgrader::VERSIONS_NONE);
        $dryRun = (bool) $input->getOption('dry-run');
        $report = $this->upgrader->run($from, $mode, $dryRun);

        $io->table(['Page', 'Locale', 'Result'], array_map(static fn (array $row): array => [$row['slug'], $row['locale'], $row['result']], $report['rows']));
        $io->text(implode(', ', array_map(static fn (string $result, int $count): string => $result . ': ' . $count, array_keys($report['counts']), $report['counts'])));
        foreach ($report['versions'] as $version) {
            $io->text(sprintf('Version published: %s v%d%s', $version['slug'], $version['version'], $version['requires_acceptance'] ? ' (acceptance required)' : ''));
        }
        $outdated = count(array_filter($report['rows'], static fn (array $row): bool => $row['result'] === LegalSeedUpgrader::OUTDATED));
        if ($dryRun) {
            $io->note('Dry run: nothing was written.');
        } else {
            $io->success(sprintf('Legal seed upgraded. Outdated copies to review in the admin: %d.', $outdated));
        }

        return $outdated > 0 && (bool) $input->getOption('fail-on-outdated') ? self::EXIT_OUTDATED : Command::SUCCESS;
    }
}
