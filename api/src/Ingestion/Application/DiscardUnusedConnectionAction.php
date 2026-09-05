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
 * принадлежит Ingestion. **Направление вызова** — то же, что
 * у ConnectOzonAccountAction: Ingestion владеет проверкой своих данных,
 * Identity исполняет запись, зависимости строго вниз, общение только
 * через Facade. **Механизм** — не тот же: у ConnectOzonAccountAction
 * нет ни замыкания, ни общей транзакции, он синхронно зовёт Facade после
 * сетевых проб. Здесь предикат передаётся колбэком и исполняется внутри
 * чужой открытой транзакции — см. ниже, почему, и что это исключение,
 * а не второй прецедент.
 *
 * **Гонка между проверкой и удалением.** Между «сейчас документов нет»
 * и самим удалением воркер может записать документ — тогда удалилось бы
 * подключение, у которого история уже есть. Ни одна простая передача
 * результата не годится: булево значение, посчитанное здесь и переданное
 * в Facade, — это ровно запрещённый CLAUDE.md §4 приём «проверил, потом
 * удалил» без защиты (значение устаревает раньше, чем доезжает до
 * DELETE). Обратный вариант — DELETE ... WHERE NOT EXISTS по
 * marketplace_raw_document прямо в репозитории Identity — читал бы
 * таблицу Ingestion мимо Facade, тем же нарушением границы модуля,
 * которое запрещено обратному вызову. Поэтому сюда передаётся колбэк
 * ($hasNoIngestedHistory), который IdentityFacade::discardUnusedOzonAccount
 * вызывает ИЗНУТРИ транзакции удаления, непосредственно перед DELETE
 * (см. докблок MarketplaceAccountRepository::deleteIfNoHistory) — тогда
 * же, когда результат ещё можно учесть, а не постфактум.
 *
 * Это штучное исключение для одной, явно описанной гонки, а не образец
 * для будущих кросс-модульных предикатов: связанность через замыкание
 * не видна ни Deptrac (замыкание — не зависимость класса), ни тестам,
 * и следующий такой колбэк придётся обосновывать заново, а не копировать
 * этот.
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
