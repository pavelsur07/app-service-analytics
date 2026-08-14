<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\MarketplaceAccountBrokenNotifier;
use App\Identity\Domain\MarketplaceAccountRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Площадка отказала в авторизации: подключение переводится в broken,
 * клиент получает письмо (ADR-007).
 *
 * Синхронизация после этого останавливается сама — планировщик перечисляет
 * только активные подключения. Именно поэтому переход обязан порождать
 * письмо: иначе получилась бы молчаливая остановка синхронизации, прямо
 * запрещённая CLAUDE.md, и клиент продолжал бы смотреть на вчерашние
 * цифры как на сегодняшние.
 *
 * Письмо отправляет тот вызов, который состояние действительно поменял:
 * условие «было active» живёт внутри UPDATE, и второй одновременный отказ
 * (у подключения две задачи в очереди — продажи и каталог) письма
 * не породит.
 */
final readonly class MarkMarketplaceAccountBrokenAction
{
    public function __construct(
        private MarketplaceAccountRepository $accounts,
        private MarketplaceAccountBrokenNotifier $notifier,
    ) {
    }

    public function __invoke(string $companyId, string $marketplaceAccountId): bool
    {
        $id = Uuid::fromString($marketplaceAccountId);

        if (!$this->accounts->markBrokenIfActive($companyId, $id)) {
            return false;
        }

        $account = $this->accounts->get($companyId, $id);
        if (null === $account) {
            // Строку только что обновили, значит она есть. Гонка с удалением
            // подключения возможна лишь теоретически, и молчать о ней нельзя.
            throw new \RuntimeException("Подключение {$marketplaceAccountId} компании {$companyId} исчезло между переводом в broken и уведомлением.");
        }

        $this->notifier->accountBroken($companyId, $account);

        return true;
    }
}
