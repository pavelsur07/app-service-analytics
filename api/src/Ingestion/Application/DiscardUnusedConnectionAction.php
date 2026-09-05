<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

use App\Identity\Application\Facade\DiscardConnectionOutcome;
use App\Identity\Application\Facade\IdentityFacade;
use App\Ingestion\Domain\MarketplaceRawDocumentRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Удаление подключения, которое ничего не загрузило.
 *
 * Клиент может подключить не тот кабинет: номер настоящий, но другого
 * магазина. Исправить нечем — external_shop_id неизменяем, замена ключа
 * на другой кабинет отвергается (WrongCabinet), отзыв наружу не выведен.
 * Владелец решил: подключение без единого загруженного документа —
 * ошибка, а не актив, и его можно удалить целиком, а не отозвать.
 *
 * Живёт в Ingestion, хотя удаляет данные Identity: признак «ничего
 * не загрузило» — отсутствие marketplace_raw_document, а эта таблица
 * принадлежит Ingestion. Тем же приёмом и по той же причине, что
 * ConnectOzonAccountAction — Ingestion проверяет своё и просит Identity
 * удалить через Facade; обратного направления нет.
 *
 * **Гонка между проверкой и удалением.** Между «сейчас документов нет»
 * и самим удалением воркер может записать документ — тогда удалилось бы
 * подключение, у которого история уже есть. Проверка «нет документов»
 * не выполняется здесь заранее и на её результате ничего не строится:
 * это был бы ровно запрещённый CLAUDE.md §4 приём «проверил, потом
 * удалил» без защиты. Вместо этого сюда передаётся колбэк
 * ($hasNoIngestedHistory), который IdentityFacade::discardUnusedOzonAccount
 * вызывает ИЗНУТРИ транзакции удаления, непосредственно перед DELETE
 * (см. докблок MarketplaceAccountRepository::deleteIfNoHistory) — тогда
 * же, когда результат ещё можно учесть, а не постфактум.
 */
final readonly class DiscardUnusedConnectionAction
{
    public function __construct(
        private MarketplaceRawDocumentRepository $rawDocuments,
        private IdentityFacade $identityFacade,
    ) {
    }

    public function __invoke(string $companyId, string $marketplaceAccountId, string $actorUserId): DiscardConnectionResult
    {
        $accountId = Uuid::fromString($marketplaceAccountId);

        $hasNoIngestedHistory = fn (): bool => !$this->rawDocuments->existsForAccount($companyId, $accountId);

        return match ($this->identityFacade->discardUnusedOzonAccount(
            $companyId,
            $marketplaceAccountId,
            $hasNoIngestedHistory,
            $actorUserId,
        )) {
            DiscardConnectionOutcome::Discarded => DiscardConnectionResult::Discarded,
            DiscardConnectionOutcome::NotFound => DiscardConnectionResult::NotFound,
            DiscardConnectionOutcome::InUse => DiscardConnectionResult::HasHistory,
        };
    }
}
