<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Command;

use Nowo\LegalKitBundle\Catalog\LegalPageCatalogInterface;
use Nowo\LegalKitBundle\Locale\LegalLocales;
use Nowo\LegalKitBundle\Seed\LegalSeed;
use Nowo\LegalKitBundle\Versioning\LegalSeedTracker;
use Nowo\LegalKitBundle\Versioning\SeedSnapshotStore;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function is_string;
use function sprintf;

/**
 * Freezes the current built-in texts of every enabled page and locale as a seed snapshot (run it before the host
 * changes its seed texts; one snapshot per instance and revision).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[AsCommand(name: self::NAME, description: 'Freeze the current built-in legal texts as a seed snapshot (versioning)')]
final class SnapshotSeedCommand extends Command
{
    public const string NAME = 'nowo:legal-kit:snapshot-seed';

    /**
     * @param LegalPageCatalogInterface $catalog Enabled pages
     * @param LegalLocales $locales Enabled locales
     * @param LegalSeedTracker $tracker Current seeds and revision
     * @param SeedSnapshotStore $snapshots Snapshot store
     */
    public function __construct(
        private readonly LegalPageCatalogInterface $catalog,
        private readonly LegalLocales $locales,
        private readonly LegalSeedTracker $tracker,
        private readonly SeedSnapshotStore $snapshots,
    ) {
        parent::__construct(self::NAME);
    }

    /**
     * Options: --revision, --force.
     *
     * @return void Nothing (side effects only)
     */
    protected function configure(): void
    {
        $this
            ->addOption('revision', null, InputOption::VALUE_REQUIRED, 'Revision name (default: seed.revision)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Replace an existing snapshot of the revision');
    }

    /**
     * Writes the snapshot.
     *
     * @return int Exit code
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $revision = $input->getOption('revision');
        $revision = is_string($revision) && $revision !== '' ? $revision : $this->tracker->revision();

        $pages = [];
        foreach ($this->catalog->pages() as $page) {
            foreach ($this->locales->all() as $locale) {
                $seed = $this->tracker->currentSeed($page->slug, $locale);
                if ($seed instanceof LegalSeed) {
                    $pages[$page->slug][$locale] = ['title' => $seed->title, 'body' => $seed->body];
                }
            }
        }

        try {
            $count = $this->snapshots->write($revision, $pages, (bool) $input->getOption('force'));
        } catch (RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }
        $io->success(sprintf('Seed snapshot "%s" written: %d page / locale entries in %s.', $revision, $count, $this->snapshots->path($revision)));

        return Command::SUCCESS;
    }
}
