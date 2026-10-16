<?php

declare(strict_types=1);

namespace App\Tests\Support\Probe;

use App\Identity\Domain\MarketplaceAccount;
use Doctrine\DBAL\Connection;

/**
 * Отметки загрузки дня (ADR-038) одного кабинета и типа — для проверок
 * обработчиков: день → `ok` (успех) или `failed` (отказ).
 */
final class DayLoadMarks
{
    /**
     * @return array<string, string>
     */
    public static function of(Connection $connection, MarketplaceAccount $account, string $reportType): array
    {
        /** @var list<array{period: string, failed: bool}> $rows */
        $rows = $connection->fetchAllAssociative(
            'SELECT period, failed_at IS NOT NULL AS failed FROM marketplace_day_load WHERE company_id = ? AND marketplace_account_id = ? AND report_type = ? ORDER BY period',
            [$account->companyId()->toRfc4122(), $account->id()->toRfc4122(), $reportType],
        );

        $marks = [];
        foreach ($rows as $row) {
            $marks[$row['period']] = $row['failed'] ? 'failed' : 'ok';
        }

        return $marks;
    }

    /**
     * Сколько дней с каким итогом: ['ok' => n, 'failed' => m].
     *
     * @return array<string, int>
     */
    public static function counts(Connection $connection, MarketplaceAccount $account, string $reportType): array
    {
        return array_count_values(self::of($connection, $account, $reportType));
    }

    private function __construct()
    {
    }
}
