<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

/**
 * Разбор `GET /api/client/statistics/expense/json` ровно в том объёме,
 * который нужен отбору кампаний SKU-разбивки (ADR-035 п. 4): у каких
 * кампаний в периоде был расход.
 *
 * Сумма разбирается в Money и сравнивается с нулём там же, а не строкой:
 * «0,00» и «0» — один ноль, и строковое сравнение однажды пропустило бы
 * кампанию или взяло лишнюю.
 */
final class OzonAdExpenseParser
{
    /**
     * Кампании с расходом больше нуля хотя бы в один день периода
     * [$from, $to] включительно — по возрастанию идентификатора.
     *
     * @return list<string>
     */
    public function campaignsWithSpend(string $body, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded) || !\is_array($decoded['rows'] ?? null) || !array_is_list($decoded['rows'])) {
            throw new \UnexpectedValueException('Ozon ad expense: no "rows" array.');
        }

        $first = $from->format('Y-m-d');
        $last = $to->format('Y-m-d');

        $campaigns = [];
        foreach ($decoded['rows'] as $row) {
            if (!\is_array($row)) {
                throw new \UnexpectedValueException('Ozon ad expense: row is not an object.');
            }

            $id = $row['id'] ?? null;
            $date = $row['date'] ?? null;
            $spent = $row['moneySpent'] ?? null;
            if (!\is_string($id) || 1 !== preg_match('/\A[0-9]{1,20}\z/', $id)
                || !\is_string($date) || 1 !== preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date)
                || !\is_string($spent)) {
                throw new \UnexpectedValueException('Ozon ad expense: row needs "id", "date" and "moneySpent".');
            }

            if ($date < $first || $date > $last) {
                continue;
            }

            if (OzonPerformanceMoney::commaDecimal($spent)->minorAmount() > 0) {
                $campaigns[$id] = true;
            }
        }

        $ids = array_map(strval(...), array_keys($campaigns));
        sort($ids, \SORT_STRING);

        return $ids;
    }
}
