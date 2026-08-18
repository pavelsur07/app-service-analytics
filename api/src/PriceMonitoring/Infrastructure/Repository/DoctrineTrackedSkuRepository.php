<?php

declare(strict_types=1);

namespace App\PriceMonitoring\Infrastructure\Repository;

use App\PriceMonitoring\Domain\TrackedSku;
use App\PriceMonitoring\Domain\TrackedSkuRepository;
use App\PriceMonitoring\Domain\TrackedSkuStatus;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * DBAL, не ORM, хотя таблицу и редактирует человек (CLAUDE.md §6).
 *
 * Обе операции — условные переходы состояния, а условие обязано жить
 * внутри запроса: «прочитать, проверить, записать» два параллельных
 * клика проходят оба (CLAUDE.md §4). Тот же приём и по той же причине,
 * что в `DoctrineExtensionTokenRepository::revokeIfActive`.
 *
 * companyId — в условии самого SQL, не фильтром после выборки: изоляция
 * арендаторов проверяется на уровне запроса, а не доверием к вызывающему.
 */
final readonly class DoctrineTrackedSkuRepository implements TrackedSkuRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function track(TrackedSku $trackedSku): void
    {
        // ON CONFLICT DO UPDATE без условия WHERE: для уже активной строки
        // это присваивание тех же значений, то есть ничего. Отдельная
        // ветка «а вдруг она уже активна» была бы проверкой перед записью,
        // от которой §4 и уводит.
        //
        // marketplace_account_id обновляется: продавец мог переподключить
        // магазин, и будущие наблюдения обязаны уехать в живой кабинет,
        // а не в отозванный. created_at и created_by_user_id в SET
        // не входят — первый, кто завёл артикул, остаётся в следе
        // навсегда: возобновление не переписывает историю задним числом.
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO tracked_sku
                    (id, company_id, marketplace_account_id, marketplace_sku,
                     status, created_at, created_by_user_id, stopped_at)
                VALUES
                    (:id, :companyId, :marketplaceAccountId, :marketplaceSku,
                     :status, :createdAt, :createdByUserId, NULL)
                ON CONFLICT (company_id, marketplace_sku)
                DO UPDATE SET
                    status = EXCLUDED.status,
                    stopped_at = NULL,
                    marketplace_account_id = EXCLUDED.marketplace_account_id
                SQL,
            [
                'id' => $trackedSku->id()->toRfc4122(),
                'companyId' => $trackedSku->companyId()->toRfc4122(),
                'marketplaceAccountId' => $trackedSku->marketplaceAccountId()->toRfc4122(),
                'marketplaceSku' => $trackedSku->marketplaceSku(),
                'status' => $trackedSku->status()->value,
                'createdAt' => $trackedSku->createdAt()->format('Y-m-d H:i:s'),
                'createdByUserId' => $trackedSku->createdByUserId()->toRfc4122(),
            ],
        );
    }

    public function stopIfActive(string $companyId, string $marketplaceSku, \DateTimeImmutable $at): bool
    {
        $affected = $this->connection->executeStatement(
            <<<'SQL'
                UPDATE tracked_sku
                SET status = :stopped, stopped_at = :at
                WHERE company_id = :companyId
                  AND marketplace_sku = :marketplaceSku
                  AND status = :active
                SQL,
            [
                'stopped' => TrackedSkuStatus::Stopped->value,
                'at' => $at->format('Y-m-d H:i:s'),
                'companyId' => $companyId,
                'marketplaceSku' => $marketplaceSku,
                'active' => TrackedSkuStatus::Active->value,
            ],
        );

        return $affected > 0;
    }

    public function activeAccountIdFor(string $companyId, string $marketplaceSku): ?Uuid
    {
        $accountId = $this->connection->fetchOne(
            <<<'SQL'
                SELECT marketplace_account_id FROM tracked_sku
                WHERE company_id = :companyId
                  AND marketplace_sku = :marketplaceSku
                  AND status = :active
                SQL,
            [
                'companyId' => $companyId,
                'marketplaceSku' => $marketplaceSku,
                'active' => TrackedSkuStatus::Active->value,
            ],
        );

        // false — строки нет вовсе; fetchOne не различает «нет строки»
        // и «в строке NULL», но колонка объявлена NOT NULL.
        if (!\is_string($accountId)) {
            return null;
        }

        return Uuid::fromString($accountId);
    }

    public function countActiveExcluding(string $companyId, string $marketplaceSku): int
    {
        // COUNT(*) здесь допустим: запрет §5 написан для факт-таблиц,
        // а у этой на компанию несколько десятков строк по построению —
        // потолок, ради которого этот счёт и делается.
        $count = $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM tracked_sku
                WHERE company_id = :companyId
                  AND status = :active
                  AND marketplace_sku <> :marketplaceSku
                SQL,
            [
                'companyId' => $companyId,
                'active' => TrackedSkuStatus::Active->value,
                'marketplaceSku' => $marketplaceSku,
            ],
        );

        if (!\is_int($count) && !\is_string($count)) {
            throw new \UnexpectedValueException('Expected COUNT(*) to be an int or a numeric string.');
        }

        return (int) $count;
    }
}
