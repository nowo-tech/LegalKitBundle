<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Command;

use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceManager;
use Nowo\LegalKitBundle\Document\LegalDocumentResolver;
use Nowo\LegalKitBundle\Document\ResolvedLegalDocument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_merge;
use function array_values;
use function hash_equals;
use function is_array;
use function is_string;
use function mb_check_encoding;
use function mb_strlen;
use function preg_match;
use function sprintf;
use function trim;

/**
 * Compares a recorded `legal_acceptance.content_hash` with the current text of a page, with and without the
 * `sanitizer.heading_anchors` ids. Read-only. Page versions do not keep their text, so only the current text can be
 * checked: a mismatch does not show what the user accepted, only that it is not this text.
 *
 * `--brand-name` (repeatable) also re-renders the text with a past `%brand%` name: with a brand provider the name can
 * change at run time without a new version, and the hash recorded before a rename covers the old name. The bundle does
 * not record which name was in effect; take the names and dates from the host's audit trail of brand changes.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[AsCommand(name: self::NAME, description: 'Check a recorded acceptance hash against the current text of a legal page')]
final class VerifyAcceptanceHashCommand extends Command
{
    public const string NAME = 'nowo:legal-kit:verify-acceptance-hash';

    /** Longest `--brand-name` accepted (the brand contract caps nothing; InstanceSettings stores at most 80). */
    public const int BRAND_NAME_MAX = 200;

    /**
     * @param LegalDocumentResolver $resolver Current text
     */
    public function __construct(private readonly LegalDocumentResolver $resolver)
    {
        parent::__construct(self::NAME);
    }

    /**
     * Arguments: slug, locale, hash.
     *
     * @return void Nothing (side effects only)
     */
    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::REQUIRED, 'Page slug')
            ->addArgument('locale', InputArgument::REQUIRED, 'Locale of the recorded acceptance')
            ->addArgument('hash', InputArgument::REQUIRED, 'The recorded content_hash')
            ->addOption('brand-name', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Also check the text rendered with this past %brand% name (repeatable)');
    }

    /**
     * Prints the result: 0 when the hash matches (with or without the anchor ids), 1 when it does not, 2 on bad input.
     *
     * @return int Exit code
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $slug     = $input->getArgument('slug');
        $locale   = $input->getArgument('locale');
        $recorded = $input->getArgument('hash');
        if (!is_string($slug) || !is_string($locale) || !is_string($recorded) || preg_match('/^[0-9a-f]{64}$/D', $recorded) !== 1) {
            $io->error('The hash must be 64 lowercase hexadecimal characters.');

            return Command::INVALID;
        }
        $names = $input->getOption('brand-name');
        $names = is_array($names) ? array_values($names) : [];
        foreach ($names as $name) {
            if (!is_string($name) || trim($name) === '' || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name) > self::BRAND_NAME_MAX || preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $name) === 1) {
                $io->error(sprintf('A --brand-name must be non-empty text of at most %d characters, without control or format characters.', self::BRAND_NAME_MAX));

                return Command::INVALID;
            }
        }

        foreach (array_merge([null], $names) as $name) {
            $document = $this->resolver->resolve($slug, $locale, $name);
            if (!$document instanceof ResolvedLegalDocument) {
                $io->error(sprintf('No text for the page "%s" in "%s".', $slug, $locale));

                return Command::INVALID;
            }
            $with = $name === null ? 'the current text' : sprintf('the current text with the brand name "%s"', $name);
            if (hash_equals($recorded, LegalAcceptanceManager::contentHash($document))) {
                $io->success(sprintf('The hash matches %s.', $with));

                return Command::SUCCESS;
            }
            if (hash_equals($recorded, LegalAcceptanceManager::contentHashWithoutAnchorIds($document))) {
                $io->success(sprintf('The hash matches %s without its anchor ids: it was recorded before sanitizer.heading_anchors applied to this text.', $with));

                return Command::SUCCESS;
            }
        }
        $io->warning($names === []
            ? 'The hash does not match the current text. Only the current text can be checked: page versions do not keep their text. After a brand rename, pass the past names with --brand-name.'
            : 'The hash does not match the current text, with the current brand or any given --brand-name. Page versions do not keep their text.');

        return Command::FAILURE;
    }
}
