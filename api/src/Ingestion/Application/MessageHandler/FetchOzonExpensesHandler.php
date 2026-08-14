<?php

declare(strict_types=1);

namespace App\Ingestion\Application\MessageHandler;

use App\Identity\Application\Facade\IdentityFacade;
use App\Ingestion\Application\Message\FetchOzonExpensesMessage;
use App\Ingestion\Domain\MarketplaceExpenseFactRepository;
use App\Ingestion\Domain\MarketplaceRawDocument;
use App\Ingestion\Domain\MarketplaceRawDocumentRepository;
use App\Ingestion\Domain\MarketplaceReportType;
use App\Ingestion\Domain\OzonAccrualByDayParser;
use App\Ingestion\Domain\OzonAuthorizationFailure;
use App\Ingestion\Domain\OzonExpensesFetcher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Клиент -> raw -> парсер -> upsert расходов (ADR-012).
 *
 * Идемпотентен целиком: raw дедуплицируется по естественному ключу
 * (ADR-006), расходы — upsert по своему, обновление только при
 * изменившейся сумме. Повторный запуск на том же дне не меняет ни строки.
 *
 * Страницы внутри дня читаются курсором last_id. Записываются они
 * по мере чтения, а не в конце: удаления исчезнувших у расходов нет
 * (начисление, однажды выпущенное, не пропадает), поэтому частичная
 * запись при обрыве не портит картину — она просто неполна, и следующий
 * проход её дополнит.
 *
 * Отказ авторизации переводит подключение в broken и уведомляет клиента
 * (ADR-007), как и остальные обработчики.
 */
#[AsMessageHandler]
final readonly class FetchOzonExpensesHandler
{
    /**
     * Потолок страниц внутри одного дня. У первого клиента день —
     * 255 начислений в одной странице; сто страниц это заведомо больше
     * любого дня и защита от курсора, который перестал двигаться.
     */
    private const int MAX_PAGES = 100;

    public function __construct(
        private IdentityFacade $identityFacade,
        private OzonExpensesFetcher $client,
        private OzonAccrualByDayParser $parser,
        private MarketplaceRawDocumentRepository $rawDocuments,
        private MarketplaceExpenseFactRepository $expenses,
    ) {
    }

    public function __invoke(FetchOzonExpensesMessage $message): void
    {
        $target = $this->identityFacade->findOzonSyncTarget($message->companyId, $message->marketplaceAccountId);
        if (null === $target) {
            throw new \RuntimeException("Marketplace account {$message->marketplaceAccountId} not found for company {$message->companyId}.");
        }

        $companyId = Uuid::fromString($target->companyId);
        $marketplaceAccountId = Uuid::fromString($target->marketplaceAccountId);
        $day = new \DateTimeImmutable($message->accrualDate);

        $lastId = '';
        $seenCursors = [];

        for ($page = 0; $page < self::MAX_PAGES; ++$page) {
            try {
                $rawBody = $this->client->fetchDay($target->clientId, $target->apiKey, $day, $lastId);
            } catch (\Throwable $failure) {
                if (!OzonAuthorizationFailure::isAuthorizationFailure($failure)) {
                    throw $failure;
                }

                $this->identityFacade->markOzonAccountBroken($message->companyId, $message->marketplaceAccountId);

                return;
            }

            $rawDocumentId = $this->rawDocuments->add(MarketplaceRawDocument::capture(
                companyId: $companyId,
                marketplaceAccountId: $marketplaceAccountId,
                reportType: MarketplaceReportType::OzonAccrualByDay,
                period: $day,
                rawBody: $rawBody,
            ));

            $parsed = $this->parser->parse($rawBody, $companyId, $marketplaceAccountId, $rawDocumentId);
            $this->expenses->upsertAll($parsed['facts']);

            if ('' === $parsed['lastId']) {
                return;
            }

            if (isset($seenCursors[$parsed['lastId']])) {
                throw new \RuntimeException("Ozon вернул повторяющийся курсор начислений за {$message->accrualDate} по подключению {$message->marketplaceAccountId} — выгрузка не двигается.");
            }
            $seenCursors[$parsed['lastId']] = true;
            $lastId = $parsed['lastId'];
        }

        throw new \RuntimeException(\sprintf('Начисления за %s по подключению %s не уместились в %d страниц.', $message->accrualDate, $message->marketplaceAccountId, self::MAX_PAGES));
    }
}
