<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\LegalKitBundle\Catalog\LegalPageCatalogInterface;
use Nowo\LegalKitBundle\Document\LegalDocumentResolver;
use Nowo\LegalKitBundle\Entity\LegalDocument;
use Nowo\LegalKitBundle\Locale\LegalLocales;
use Nowo\LegalKitBundle\Repository\LegalDocumentRepository;
use Nowo\LegalKitBundle\Sanitizer\LegalHtmlSanitizerInterface;
use Nowo\LegalKitBundle\Seed\LegalSeed;
use Nowo\LegalKitBundle\Seed\LegalSeedRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

/**
 * Materialise mode (`seed.materialize: true`): stores the sanitised seed of every missing page × locale as a row.
 * Never overwrites an existing row; callable from the host's platform seeding.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class SeedCommand extends Command
{
    public const string NAME = 'nowo:legal-kit:seed';

    /**
     * @param LegalPageCatalogInterface $catalog Enabled pages
     * @param LegalLocales $locales Enabled locales
     * @param LegalDocumentRepository $documents Stored rows
     * @param LegalSeedRegistry $seeds Seeds
     * @param LegalHtmlSanitizerInterface $sanitizer Sanitiser
     * @param EntityManagerInterface $entityManager Unit of work
     * @param bool $materialize `seed.materialize`
     */
    public function __construct(
        private readonly LegalPageCatalogInterface $catalog,
        private readonly LegalLocales $locales,
        private readonly LegalDocumentRepository $documents,
        private readonly LegalSeedRegistry $seeds,
        private readonly LegalHtmlSanitizerInterface $sanitizer,
        private readonly EntityManagerInterface $entityManager,
        private readonly bool $materialize = false,
    ) {
        parent::__construct(self::NAME);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Store the built-in legal text as rows for every missing page and locale (seed.materialize)')
            ->addOption('missing-only', null, InputOption::VALUE_NONE, 'Only insert missing rows (always the case; kept for explicit deploy scripts)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Run even when seed.materialize is false');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->materialize && !(bool) $input->getOption('force')) {
            $io->note('seed.materialize is false: the seed is published without rows. Use --force to store it anyway.');

            return Command::SUCCESS;
        }

        $locales  = $this->locales->all();
        $stored   = $this->documents->findByLocales($locales);
        $inserted = 0;
        foreach ($locales as $locale) {
            foreach ($this->catalog->slugs() as $slug) {
                if (isset($stored[LegalDocumentRepository::key($slug, $locale)])) {
                    continue;
                }
                $seed = $this->seeds->find($slug, $locale);
                if (!$seed instanceof LegalSeed) {
                    continue;
                }
                $body = $this->sanitizer->sanitize($seed->body);
                if (!LegalDocumentResolver::hasText($body)) {
                    continue;
                }
                $document = new LegalDocument($slug, $locale);
                $document->setTitle($seed->title);
                $document->setBody($body);
                $this->entityManager->persist($document);
                ++$inserted;
            }
        }
        $this->entityManager->flush();
        $io->success(sprintf('%d legal document(s) inserted.', $inserted));

        return Command::SUCCESS;
    }
}
