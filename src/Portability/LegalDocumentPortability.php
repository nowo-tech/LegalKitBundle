<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Portability;

use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Nowo\LegalKitBundle\Catalog\LegalPageCatalogInterface;
use Nowo\LegalKitBundle\Document\LegalDocumentResolver;
use Nowo\LegalKitBundle\Entity\LegalDocument;
use Nowo\LegalKitBundle\Event\LegalDocumentsImportedEvent;
use Nowo\LegalKitBundle\Locale\LegalLocales;
use Nowo\LegalKitBundle\Repository\LegalDocumentRepository;
use Nowo\LegalKitBundle\Sanitizer\LegalHtmlSanitizerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

use function array_is_list;
use function count;
use function is_array;
use function is_string;
use function mb_strlen;
use function sprintf;
use function trim;

/**
 * Ops-seed portability of operator copies (REQ-OPSSEED-004): `legal_documents[]` = list of {slug, locale, title, body}.
 *
 * - {@see export()}: stored rows only, sorted, tokens kept;
 * - {@see normalize()}: validates a decoded payload (unknown slug / locale, missing title, empty body, duplicates →
 *   InvalidArgumentException naming the index);
 * - {@see apply()}: upserts sanitised rows in one unit of work; flushes only with `$flush` so the host ops-seed keeps
 *   a single transaction.
 *
 * @phpstan-type LegalDocumentRow array{slug: string, locale: string, title: string, body: string}
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class LegalDocumentPortability
{
    /**
     * @param LegalDocumentRepository $documents Stored rows
     * @param EntityManagerInterface $entityManager Unit of work
     * @param LegalPageCatalogInterface $catalog Enabled pages
     * @param LegalLocales $locales Enabled locales
     * @param LegalHtmlSanitizerInterface $sanitizer HTML sanitiser
     * @param EventDispatcherInterface|null $dispatcher Dispatches LegalDocumentsImportedEvent after a flush
     */
    public function __construct(
        private LegalDocumentRepository $documents,
        private EntityManagerInterface $entityManager,
        private LegalPageCatalogInterface $catalog,
        private LegalLocales $locales,
        private LegalHtmlSanitizerInterface $sanitizer,
        private ?EventDispatcherInterface $dispatcher = null,
    ) {
    }

    /**
     * Every stored row, sorted by slug and locale (tokens kept).
     *
     * @return list<LegalDocumentRow>
     */
    public function export(): array
    {
        $rows = [];
        foreach ($this->documents->findAllSorted() as $document) {
            $rows[] = [
                'slug'   => $document->getSlug(),
                'locale' => $document->getLocale(),
                'title'  => $document->getTitle(),
                'body'   => $document->getBody(),
            ];
        }

        return $rows;
    }

    /**
     * Validates a decoded `legal_documents` payload.
     *
     * @throws InvalidArgumentException With the index of the first invalid entry
     *
     * @return list<LegalDocumentRow>
     */
    public function normalize(mixed $payload): array
    {
        if (!is_array($payload) || !array_is_list($payload)) {
            throw new InvalidArgumentException('legal_documents must be a list.');
        }

        $rows = [];
        $seen = [];
        foreach ($payload as $index => $entry) {
            if (!is_array($entry)) {
                throw new InvalidArgumentException(sprintf('legal_documents[%d] must be an object.', $index));
            }
            foreach (['slug', 'locale', 'title', 'body'] as $field) {
                if (!isset($entry[$field]) || !is_string($entry[$field])) {
                    throw new InvalidArgumentException(sprintf('legal_documents[%d].%s must be a string.', $index, $field));
                }
            }
            /** @var array{slug: string, locale: string, title: string, body: string} $entry */
            if (!$this->catalog->has($entry['slug'])) {
                throw new InvalidArgumentException(sprintf('legal_documents[%d].slug "%s" is not an enabled legal page.', $index, $entry['slug']));
            }
            if (!$this->locales->has($entry['locale'])) {
                throw new InvalidArgumentException(sprintf('legal_documents[%d].locale "%s" is not an enabled locale.', $index, $entry['locale']));
            }
            $title = trim($entry['title']);
            if ($title === '' || mb_strlen($title) > LegalDocument::TITLE_MAX_LENGTH) {
                throw new InvalidArgumentException(sprintf('legal_documents[%d].title must have 1 to %d characters.', $index, LegalDocument::TITLE_MAX_LENGTH));
            }
            if (mb_strlen($entry['body']) > LegalDocument::BODY_MAX_LENGTH) {
                throw new InvalidArgumentException(sprintf('legal_documents[%d].body exceeds %d characters.', $index, LegalDocument::BODY_MAX_LENGTH));
            }
            if (!LegalDocumentResolver::hasText($this->sanitizer->sanitize($entry['body']))) {
                throw new InvalidArgumentException(sprintf('legal_documents[%d].body is empty after sanitising.', $index));
            }
            $key = $entry['slug'] . "\0" . $entry['locale'];
            if (isset($seen[$key])) {
                throw new InvalidArgumentException(sprintf('legal_documents[%d] duplicates %s/%s.', $index, $entry['slug'], $entry['locale']));
            }
            $seen[$key] = true;
            $rows[]     = ['slug' => $entry['slug'], 'locale' => $entry['locale'], 'title' => $title, 'body' => $entry['body']];
        }

        return $rows;
    }

    /**
     * Upserts normalised rows (sanitised bodies) and returns how many were written.
     *
     * @param list<LegalDocumentRow> $rows Output of {@see normalize()}
     * @param object|null $actor User set as created_by / updated_by before persisting; AuditKit's blame listener replaces it
     *                           with the signed-in user (null in CLI) when blame is enabled for the user class
     * @param bool $flush Flush (and dispatch LegalDocumentsImportedEvent); false keeps the host transaction open
     *
     * @return int Number of rows written
     */
    public function apply(array $rows, ?object $actor = null, bool $flush = false): int
    {
        $written = [];
        foreach ($rows as $row) {
            $document = $this->documents->findOneBySlugAndLocale($row['slug'], $row['locale']);
            if (!$document instanceof LegalDocument) {
                $document = new LegalDocument($row['slug'], $row['locale']);
                if ($actor !== null) {
                    $document->setCreatedBy($actor);
                }
            }
            $document->setTitle($row['title']);
            $document->setBody($this->sanitizer->sanitize($row['body']));
            if ($actor !== null) {
                $document->setUpdatedBy($actor);
            }
            $this->entityManager->persist($document);
            $written[] = ['slug' => $row['slug'], 'locale' => $row['locale']];
        }

        if ($flush) {
            $this->entityManager->flush();
            $this->dispatcher?->dispatch(new LegalDocumentsImportedEvent($written));
        }

        return count($written);
    }
}
