<?php

declare(strict_types=1);

namespace App\Planning\Infrastructure\Import;

use App\Planning\Domain\DailyPlan;
use App\Planning\Domain\PlanImportIssue;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;

final class XlsxDailyPlanReader
{
    public const int MAX_FILE_BYTES = 10_000_000;
    public const int MAX_ROWS = 10_000;
    private const int MAX_UNCOMPRESSED_BYTES = 50_000_000;
    private const int MAX_ARCHIVE_ENTRIES = 10_000;
    private const int MAX_COMPRESSION_RATIO = 200;

    public function read(string $path): XlsxDailyPlanReadResult
    {
        $archiveIssue = $this->validateArchive($path);
        if (null !== $archiveIssue) {
            return new XlsxDailyPlanReadResult([], [$archiveIssue]);
        }

        $reader = new Reader();
        try {
            $reader->open($path);
            $sheets = iterator_to_array($reader->getSheetIterator(), false);
            if (1 !== \count($sheets)) {
                return new XlsxDailyPlanReadResult([], [new PlanImportIssue(null, 'sheet_count_invalid', 'Файл должен содержать один лист.')]);
            }

            return $this->normalize($sheets[0]->getRowIterator());
        } catch (\Throwable) {
            return new XlsxDailyPlanReadResult([], [new PlanImportIssue(null, 'xlsx_invalid', 'Файл XLSX не удалось прочитать.')]);
        } finally {
            $reader->close();
        }
    }

    /** @param iterable<Row> $sourceRows */
    private function normalize(iterable $sourceRows): XlsxDailyPlanReadResult
    {
        $rows = [];
        $issues = [];
        $seen = [];
        $dataRows = 0;
        $rowNumber = 0;
        foreach ($sourceRows as $sourceRow) {
            ++$rowNumber;
            if (1 === $rowNumber) {
                if (['SKU', 'Дата', 'План, шт.'] !== array_values($sourceRow->toArray())) {
                    return new XlsxDailyPlanReadResult([], [new PlanImportIssue(1, 'headers_invalid', 'Ожидаются колонки SKU, Дата, План, шт.')]);
                }
                continue;
            }
            if ($sourceRow->isEmpty()) {
                continue;
            }
            ++$dataRows;
            if ($dataRows > self::MAX_ROWS) {
                $issues[] = new PlanImportIssue($rowNumber, 'row_limit_exceeded', 'В файле больше 10 000 строк.');
                break;
            }

            if ($this->containsFormula($sourceRow)) {
                $issues[] = new PlanImportIssue($rowNumber, 'formula_forbidden', 'Формулы в обязательных ячейках запрещены.');
                continue;
            }

            $values = array_values($sourceRow->toArray());
            $sku = $values[0] ?? null;
            $date = $values[1] ?? null;
            $quantity = $values[2] ?? null;
            $rowIssues = [];

            if (!\is_string($sku) || !DailyPlan::isMarketplaceSkuValid($sku)) {
                $rowIssues[] = new PlanImportIssue($rowNumber, 'sku_invalid', 'SKU не задан или некорректен.');
            }
            $businessDate = $this->date($date);
            if (null === $businessDate) {
                $rowIssues[] = new PlanImportIssue($rowNumber, 'date_invalid', 'Дата не задана или некорректна.');
            }
            $normalizedQuantity = $this->quantity($quantity);
            if (null === $normalizedQuantity) {
                $rowIssues[] = new PlanImportIssue($rowNumber, 'quantity_invalid', 'План должен быть целым неотрицательным числом.');
            }

            if (\is_string($sku) && DailyPlan::isMarketplaceSkuValid($sku) && null !== $businessDate) {
                $key = $sku."\0".$businessDate;
                if (isset($seen[$key])) {
                    $rowIssues[] = new PlanImportIssue($rowNumber, 'duplicate_key', 'Пара SKU + дата повторяется.');
                } else {
                    $seen[$key] = true;
                }
            }

            array_push($issues, ...$rowIssues);
            if ([] === $rowIssues) {
                \assert(\is_string($sku) && null !== $businessDate && null !== $normalizedQuantity);
                $rows[] = new PlanImportRow($rowNumber, $sku, $businessDate, $normalizedQuantity);
            }
        }
        if (0 === $rowNumber) {
            return new XlsxDailyPlanReadResult([], [new PlanImportIssue(1, 'headers_invalid', 'Ожидаются колонки SKU, Дата, План, шт.')]);
        }
        if (0 === $dataRows) {
            $issues[] = new PlanImportIssue(null, 'data_rows_required', 'Файл не содержит строк плана.');
        }

        return new XlsxDailyPlanReadResult($rows, $issues);
    }

    private function containsFormula(Row $row): bool
    {
        foreach (\array_slice($row->cells, 0, 3) as $cell) {
            if ($cell instanceof FormulaCell) {
                return true;
            }
        }

        return false;
    }

    private function date(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if ((\is_int($value) || \is_float($value)) && $value >= 1 && $value <= 2_958_465) {
            $days = (int) floor((float) $value);

            return (new \DateTimeImmutable('1899-12-30', new \DateTimeZone('Europe/Moscow')))
                ->modify('+'.$days.' days')->format('Y-m-d');
        }
        if (!\is_string($value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('Europe/Moscow'));

        return false !== $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function quantity(mixed $value): ?int
    {
        if (\is_float($value) && floor($value) === $value) {
            $value = (int) $value;
        }

        return \is_int($value) && $value >= 0 && $value <= DailyPlan::MAX_QUANTITY ? $value : null;
    }

    private function validateArchive(string $path): ?PlanImportIssue
    {
        $size = @filesize($path);
        if (false === $size || 0 === $size || $size > self::MAX_FILE_BYTES) {
            return new PlanImportIssue(null, 'file_size_invalid', 'Файл пуст или превышает 10 МБ.');
        }
        $zip = new \ZipArchive();
        if (true !== $zip->open($path)) {
            return new PlanImportIssue(null, 'xlsx_invalid', 'Файл не является корректным XLSX.');
        }
        try {
            if ($zip->numFiles > self::MAX_ARCHIVE_ENTRIES) {
                return new PlanImportIssue(null, 'archive_limit_exceeded', 'Архив XLSX слишком велик.');
            }
            $total = 0;
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $stat = $zip->statIndex($index);
                if (false === $stat) {
                    return new PlanImportIssue(null, 'xlsx_invalid', 'Структура XLSX повреждена.');
                }
                $entrySize = (int) ($stat['size'] ?? 0);
                $compressed = (int) ($stat['comp_size'] ?? 0);
                $total += $entrySize;
                if ($total > self::MAX_UNCOMPRESSED_BYTES || ($entrySize > 1_000_000 && $entrySize > max(1, $compressed) * self::MAX_COMPRESSION_RATIO)) {
                    return new PlanImportIssue(null, 'archive_limit_exceeded', 'Распакованный XLSX превышает безопасный лимит.');
                }
            }
        } finally {
            $zip->close();
        }

        return null;
    }
}
