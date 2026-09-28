<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

/**
 * Разбор SKU-разбивки рекламы (ADR-035 п. 1–2) в двух формах площадки:
 *
 * - асинхронный отчёт `POST /api/client/statistics/json`
 *   (`ozon_ad_sku_report`): `{campaign_id: {report: {rows}}}`, дата
 *   `d.m.Y`, сумма `moneySpent` с запятой; нулевые поля площадка опускает;
 * - синхронный `POST /api/client/statistics/products/sku`
 *   (`ozon_ad_sku_day`): `{rows}`, кампания в строке `campaignId`, дата ISO,
 *   сумма `expense` с точкой.
 *
 * Суммы берутся из строки как есть — итог кампании по SKU не делится
 * (ADR-035). Строка с нулевым расходом возвращается: иначе корректировка
 * до нуля не перезаписала бы старое значение факта.
 *
 * Ключевые поля обязательны: пропавшая кампания, дата или SKU — ошибка
 * разбора, а не пустое значение. Отсутствующая сумма в асинхронном
 * отчёте — ноль (так площадка пишет нули), в синхронном — ошибка: там
 * поле приходит всегда.
 *
 * Тройка «кампания × день × SKU» в одном ответе дважды — тоже ошибка
 * разбора: какая из двух сумм верна, из ответа не понять, а запись
 * одним upsert такой пачки не допускает.
 *
 * Строки отдаются по одной, генератором: вызывающий пишет их порциями
 * по ходу разбора, и список расходов целиком в памяти не собирается
 * (CLAUDE.md §6). Ошибка на строке прерывает разбор с этого места;
 * строки до неё к этому моменту уже отданы.
 */
final class OzonAdSkuReportParser
{
    /**
     * @return \Generator<int, OzonAdSkuExpense>
     */
    public function parseReport(string $body): \Generator
    {
        $decoded = self::decode($body);
        // Верхний уровень — объект по кампаниям, не список: `[…]`
        // означал бы другую форму ответа.
        if ([] !== $decoded && array_is_list($decoded)) {
            throw new \UnexpectedValueException('Ozon SKU report: body is a list, not an object keyed by campaign.');
        }

        $seen = [];
        foreach ($decoded as $campaignId => $campaign) {
            $campaignId = self::campaignId((string) $campaignId);
            if (!\is_array($campaign) || !\is_array($campaign['report'] ?? null) || !\is_array($campaign['report']['rows'] ?? null)
                || !array_is_list($campaign['report']['rows'])) {
                throw new \UnexpectedValueException("Ozon SKU report: campaign {$campaignId} has no report rows.");
            }

            foreach ($campaign['report']['rows'] as $row) {
                if (!\is_array($row)) {
                    throw new \UnexpectedValueException('Ozon SKU report: row is not an object.');
                }

                $spent = $row['moneySpent'] ?? '0,00';
                if (!\is_string($spent)) {
                    throw new \UnexpectedValueException('Ozon SKU report: "moneySpent" is not a string.');
                }

                yield self::once($seen, OzonAdSkuExpense::spent(
                    $campaignId,
                    self::date(self::string($row, 'date'), 'd.m.Y'),
                    self::sku($row),
                    OzonPerformanceMoney::commaDecimal($spent),
                ));
            }
        }
    }

    /**
     * @return \Generator<int, OzonAdSkuExpense>
     */
    public function parseDay(string $body): \Generator
    {
        $decoded = self::decode($body);
        if (!\is_array($decoded['rows'] ?? null) || !array_is_list($decoded['rows'])) {
            throw new \UnexpectedValueException('Ozon products/sku: no "rows" array.');
        }

        $seen = [];
        foreach ($decoded['rows'] as $row) {
            if (!\is_array($row)) {
                throw new \UnexpectedValueException('Ozon products/sku: row is not an object.');
            }

            yield self::once($seen, OzonAdSkuExpense::spent(
                self::campaignId(self::string($row, 'campaignId')),
                self::date(self::string($row, 'date'), 'Y-m-d'),
                self::sku($row),
                OzonPerformanceMoney::dotDecimal(self::string($row, 'expense')),
            ));
        }
    }

    /**
     * @param array<string, true> $seen
     */
    private static function once(array &$seen, OzonAdSkuExpense $expense): OzonAdSkuExpense
    {
        $key = AdSkuExpenseFact::sourceRowId($expense->campaignId, $expense->businessDate, $expense->marketplaceSku);
        if (isset($seen[$key])) {
            throw new \UnexpectedValueException("Ozon SKU report: row {$key} appears twice.");
        }
        $seen[$key] = true;

        return $expense;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function decode(string $body): array
    {
        $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new \UnexpectedValueException('Ozon SKU report: body is not an object.');
        }

        return $decoded;
    }

    private static function campaignId(string $id): string
    {
        if (1 !== preg_match('/\A[0-9]{1,20}\z/', $id)) {
            throw new \UnexpectedValueException('Ozon SKU report: campaign id is not a digit string.');
        }

        return $id;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private static function sku(array $row): string
    {
        $sku = self::string($row, 'sku');
        if (1 !== preg_match('/\A[0-9]{1,20}\z/', $sku)) {
            throw new \UnexpectedValueException('Ozon SKU report: sku is not a digit string.');
        }

        return $sku;
    }

    private static function date(string $value, string $format): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!'.$format, $value);
        if (false === $date || $date->format($format) !== $value) {
            throw new \UnexpectedValueException("Ozon SKU report: date '{$value}' is not {$format}.");
        }

        return $date;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private static function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!\is_string($value) || '' === $value) {
            throw new \UnexpectedValueException("Ozon SKU report: \"{$key}\" is missing.");
        }

        return $value;
    }
}
