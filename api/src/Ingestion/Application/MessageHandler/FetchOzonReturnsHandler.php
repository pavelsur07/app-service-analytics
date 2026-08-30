<?php

declare(strict_types=1);

namespace App\Ingestion\Application\MessageHandler;

use App\Identity\Application\Facade\IdentityFacade;
use App\Ingestion\Application\Message\FetchOzonReturnsMessage;
use App\Ingestion\Domain\MarketplaceRawDocument;
use App\Ingestion\Domain\MarketplaceRawDocumentRepository;
use App\Ingestion\Domain\MarketplaceReportType;
use App\Ingestion\Domain\MarketplaceReturnFactRepository;
use App\Ingestion\Domain\OzonAuthorizationFailure;
use App\Ingestion\Domain\OzonReturnsFetcher;
use App\Ingestion\Domain\OzonReturnsListParser;
use Doctrine\DBAL\Connection;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Uid\Uuid;

/**
 * Paginated /v1/returns/list -> immutable raw pages -> return fact upsert.
 */
#[AsMessageHandler]
final readonly class FetchOzonReturnsHandler
{
    private const int MAX_PAGES = 100;
    private const int LOCK_TTL_SECONDS = 900;
    private const string TIMEZONE = 'Europe/Moscow';

    public function __construct(
        private IdentityFacade $identityFacade,
        private OzonReturnsFetcher $client,
        private OzonReturnsListParser $parser,
        private MarketplaceRawDocumentRepository $rawDocuments,
        private MarketplaceReturnFactRepository $returns,
        private Connection $connection,
        private LockFactory $lockFactory,
    ) {
    }

    public function __invoke(FetchOzonReturnsMessage $message): void
    {
        $lock = $this->lockFactory->createLock(
            'ozon-returns-'.$message->marketplaceAccountId,
            self::LOCK_TTL_SECONDS,
        );
        if (!$lock->acquire()) {
            throw new RecoverableMessageHandlingException("Ozon returns sync is already running for account {$message->marketplaceAccountId}.", retryDelay: 5_000);
        }

        try {
            $this->sync($message, $lock);
        } finally {
            $lock->release();
        }
    }

    private function sync(FetchOzonReturnsMessage $message, SharedLockInterface $lock): void
    {
        $target = $this->identityFacade->findOzonSyncTarget($message->companyId, $message->marketplaceAccountId);
        if (null === $target) {
            throw new \RuntimeException("Marketplace account {$message->marketplaceAccountId} not found for company {$message->companyId}.");
        }

        $timezone = new \DateTimeZone(self::TIMEZONE);
        $fromDay = self::parseDay($message->from, $timezone);
        $toDay = self::parseDay($message->to, $timezone);
        if (null === $fromDay || null === $toDay || $toDay < $fromDay) {
            throw new \InvalidArgumentException('Ozon returns range must contain valid inclusive Y-m-d dates.');
        }

        $utc = new \DateTimeZone('UTC');
        $from = $fromDay->setTimezone($utc);
        $to = $toDay->modify('+1 day')->modify('-1 second')->setTimezone($utc);
        $companyId = Uuid::fromString($target->companyId);
        $accountId = Uuid::fromString($target->marketplaceAccountId);
        $lastId = 0;
        /** @var array<int, true> $seenCursors */
        $seenCursors = [];
        /** @var list<array{rawDocumentId: Uuid, requestLastId: int}> $pages */
        $pages = [];

        for ($pageNumber = 1; $pageNumber <= self::MAX_PAGES; ++$pageNumber) {
            try {
                $rawBody = $this->client->fetchPage(
                    $target->clientId,
                    $target->apiKey,
                    $from,
                    $to,
                    $lastId,
                );
            } catch (\Throwable $failure) {
                if (!OzonAuthorizationFailure::isAuthorizationFailure($failure)) {
                    throw $failure;
                }

                $this->identityFacade->markOzonAccountBroken($message->companyId, $message->marketplaceAccountId);

                return;
            }

            // Сначала raw, потом parse: даже schema drift должен оставить
            // точный ответ для расследования и повторного разбора.
            $rawDocumentId = $this->rawDocuments->add(MarketplaceRawDocument::capture(
                companyId: $companyId,
                marketplaceAccountId: $accountId,
                reportType: MarketplaceReportType::OzonReturnsList,
                period: $fromDay,
                rawBody: $rawBody,
            ));
            $parsed = $this->parser->parse($rawBody, $companyId, $accountId, $rawDocumentId, $lastId);
            $pages[] = ['rawDocumentId' => $rawDocumentId, 'requestLastId' => $lastId];
            $lock->refresh();

            if (!$parsed->hasNext) {
                // Facts become visible only after pagination is known to be complete.
                // Raw pages remain available for diagnosing schema or cursor failures.
                // Re-read and parse one raw page at a time so a maximum-size
                // export does not retain tens of thousands of fact objects.
                $this->connection->transactional(function () use ($pages, $companyId, $accountId, $target): void {
                    foreach ($pages as $page) {
                        $rawBody = $this->rawDocuments->body($target->companyId, $accountId, $page['rawDocumentId']);
                        $facts = $this->parser->parse(
                            $rawBody,
                            $companyId,
                            $accountId,
                            $page['rawDocumentId'],
                            $page['requestLastId'],
                        )->facts;
                        $this->returns->upsertAll($facts);
                    }
                });

                return;
            }

            $nextLastId = $parsed->lastId;
            if (null === $nextLastId) {
                throw new \UnexpectedValueException('Ozon returns page has_next=true has no cursor.');
            }
            if (isset($seenCursors[$nextLastId])) {
                throw new \RuntimeException('Ozon returns cursor repeated — pagination does not advance.');
            }
            $seenCursors[$nextLastId] = true;
            $lastId = $nextLastId;
        }

        throw new \RuntimeException(\sprintf('Возвраты по подключению %s не уместились в %d страниц; выгрузка неполна.', $message->marketplaceAccountId, self::MAX_PAGES));
    }

    private static function parseDay(string $value, \DateTimeZone $timezone): ?\DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        if (false === $parsed || $parsed->format('Y-m-d') !== $value) {
            return null;
        }

        return $parsed;
    }
}
