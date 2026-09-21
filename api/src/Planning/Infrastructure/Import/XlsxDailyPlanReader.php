<?php

declare(strict_types=1);

namespace App\Planning\Infrastructure\Import;

use App\Planning\Domain\DailyPlan;
use App\Planning\Domain\PlanImportIssue;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Exception\OpenSpoutException;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;

final class XlsxDailyPlanReader
{
    public const int MAX_FILE_BYTES = 10_000_000;
    public const int MAX_ROWS = 10_000;
    public const int MAX_WORKSHEET_ROW = 50_000;
    private const int MAX_WORKSHEET_COLUMN = 16_384;
    private const int MAX_UNCOMPRESSED_BYTES = 50_000_000;
    private const int MAX_ARCHIVE_ENTRIES = 10_000;

    public function read(string $path): XlsxDailyPlanReadResult
    {
        $archiveIssue = $this->validateArchive($path);
        if (null !== $archiveIssue) {
            return new XlsxDailyPlanReadResult([], [$archiveIssue]);
        }

        $reader = new Reader(new Options(SHOULD_PRESERVE_EMPTY_ROWS: true));
        try {
            $reader->open($path);
            $sheets = iterator_to_array($reader->getSheetIterator(), false);
            if (1 !== \count($sheets)) {
                return new XlsxDailyPlanReadResult([], [new PlanImportIssue(null, 'sheet_count_invalid', 'Файл должен содержать один лист.')]);
            }

            return $this->normalize($sheets[0]->getRowIterator());
        } catch (OpenSpoutException) {
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
        $skuReferences = [];
        $seen = [];
        $dataRows = 0;
        $rowNumber = 0;
        foreach ($sourceRows as $sourceRow) {
            ++$rowNumber;
            if (1 === $rowNumber) {
                $header = array_values($sourceRow->toArray());
                if (['SKU', 'Дата', 'План, шт.'] !== \array_slice($header, 0, 3)
                    || $this->hasNonEmptyExtraCells($header)) {
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

            $values = array_values($sourceRow->toArray());
            $rowIssues = [];
            if ($this->hasNonEmptyExtraCells($values)) {
                $rowIssues[] = new PlanImportIssue($rowNumber, 'columns_invalid', 'Дополнительные колонки не поддерживаются.');
            }
            foreach (\array_slice($sourceRow->cells, 0, 3, true) as $index => $cell) {
                if ($cell instanceof FormulaCell) {
                    $values[$index] = null;
                    $rowIssues[] = new PlanImportIssue($rowNumber, 'formula_forbidden', 'Формулы в обязательных ячейках запрещены.');
                }
            }
            $sku = $this->sku($values[0] ?? null);
            $date = $values[1] ?? null;
            $quantity = $values[2] ?? null;

            if (null === $sku || !DailyPlan::isMarketplaceSkuValid($sku)) {
                $rowIssues[] = new PlanImportIssue($rowNumber, 'sku_invalid', 'SKU не задан или некорректен.');
            } else {
                $skuReferences[] = new PlanImportSkuReference($rowNumber, $sku);
            }
            $businessDate = $this->date($date);
            if (null === $businessDate) {
                $rowIssues[] = new PlanImportIssue($rowNumber, 'date_invalid', 'Дата не задана или некорректна.');
            }
            $normalizedQuantity = $this->quantity($quantity);
            if (null === $normalizedQuantity) {
                $rowIssues[] = new PlanImportIssue($rowNumber, 'quantity_invalid', 'План должен быть целым неотрицательным числом.');
            }

            if (null !== $sku && DailyPlan::isMarketplaceSkuValid($sku) && null !== $businessDate) {
                $key = $sku."\0".$businessDate;
                if (isset($seen[$key])) {
                    $rowIssues[] = new PlanImportIssue($rowNumber, 'duplicate_key', 'Пара SKU + дата повторяется.');
                } else {
                    $seen[$key] = true;
                }
            }

            array_push($issues, ...$rowIssues);
            if ([] === $rowIssues) {
                \assert(null !== $sku && null !== $businessDate && null !== $normalizedQuantity);
                $rows[] = new PlanImportRow($rowNumber, $sku, $businessDate, $normalizedQuantity);
            }
        }
        if (0 === $rowNumber) {
            return new XlsxDailyPlanReadResult([], [new PlanImportIssue(1, 'headers_invalid', 'Ожидаются колонки SKU, Дата, План, шт.')]);
        }
        if (0 === $dataRows) {
            $issues[] = new PlanImportIssue(null, 'data_rows_required', 'Файл не содержит строк плана.');
        }

        return new XlsxDailyPlanReadResult($rows, $issues, $skuReferences);
    }

    private function date(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (!\is_string($value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('Europe/Moscow'));

        return false !== $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function sku(mixed $value): ?string
    {
        if (\is_int($value) && $value >= 0) {
            return (string) $value;
        }
        if (\is_float($value) && is_finite($value) && $value >= 0 && $value <= 9_007_199_254_740_991 && floor($value) === $value) {
            return number_format($value, 0, '.', '');
        }

        return \is_string($value) ? $value : null;
    }

    private function quantity(mixed $value): ?int
    {
        if (\is_float($value)
            && is_finite($value)
            && $value >= 0
            && $value <= DailyPlan::MAX_QUANTITY
            && floor($value) === $value) {
            $value = (int) $value;
        }

        return \is_int($value) && $value >= 0 && $value <= DailyPlan::MAX_QUANTITY ? $value : null;
    }

    /** @param list<mixed> $values */
    private function hasNonEmptyExtraCells(array $values): bool
    {
        foreach (\array_slice($values, 3) as $value) {
            if (null !== $value && '' !== $value) {
                return true;
            }
        }

        return false;
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
        $archiveEntries = [];
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
                $entryName = $stat['name'] ?? null;
                if (!\is_string($entryName)) {
                    return new PlanImportIssue(null, 'xlsx_invalid', 'Структура XLSX повреждена.');
                }
                $archiveEntries[$entryName] = true;
                if (str_ends_with($entryName, '/')) {
                    continue;
                }
                $stream = $zip->getStreamIndex($index);
                if (false === $stream) {
                    return new PlanImportIssue(null, 'xlsx_invalid', 'Структура XLSX повреждена.');
                }
                $entrySize = 0;
                try {
                    while (!feof($stream)) {
                        $chunk = fread($stream, 8192);
                        if (false === $chunk || ('' === $chunk && !feof($stream))) {
                            return new PlanImportIssue(null, 'xlsx_invalid', 'Структура XLSX повреждена.');
                        }
                        $bytes = \strlen($chunk);
                        $entrySize += $bytes;
                        $total += $bytes;
                        if ($total > self::MAX_UNCOMPRESSED_BYTES) {
                            return new PlanImportIssue(null, 'archive_limit_exceeded', 'Распакованный XLSX превышает безопасный лимит.');
                        }
                    }
                } finally {
                    fclose($stream);
                }
            }
        } finally {
            $zip->close();
        }

        $worksheetEntries = $this->worksheetEntries($path, $archiveEntries);
        if (null === $worksheetEntries) {
            return new PlanImportIssue(null, 'xlsx_invalid', 'Структура XLSX повреждена.');
        }

        return $this->validateWorksheetRows($path, $worksheetEntries);
    }

    /**
     * @param array<string, true> $archiveEntries
     *
     * @return list<string>|null
     */
    private function worksheetEntries(string $path, array $archiveEntries): ?array
    {
        $sheetIds = [];
        $workbook = \XMLReader::open('zip://'.$path.'#xl/workbook.xml', null, \LIBXML_NONET | \LIBXML_COMPACT);
        if (false === $workbook) {
            return null;
        }
        try {
            while ($workbook->read()) {
                if (\XMLReader::ELEMENT === $workbook->nodeType && 'sheet' === $workbook->localName) {
                    $id = $workbook->getAttribute('r:id');
                    if (null === $id || '' === $id) {
                        return null;
                    }
                    $sheetIds[] = $id;
                }
            }
        } finally {
            $workbook->close();
        }
        if ([] === $sheetIds) {
            return null;
        }

        $targets = [];
        $relationships = \XMLReader::open('zip://'.$path.'#xl/_rels/workbook.xml.rels', null, \LIBXML_NONET | \LIBXML_COMPACT);
        if (false === $relationships) {
            return null;
        }
        try {
            while ($relationships->read()) {
                if (\XMLReader::ELEMENT !== $relationships->nodeType || 'Relationship' !== $relationships->localName) {
                    continue;
                }
                $id = $relationships->getAttribute('Id');
                $target = $relationships->getAttribute('Target');
                if (null !== $id && null !== $target) {
                    $targets[$id] = $target;
                }
            }
        } finally {
            $relationships->close();
        }

        $entries = [];
        foreach ($sheetIds as $sheetId) {
            $target = $targets[$sheetId] ?? null;
            if (null === $target || '' === $target || str_contains($target, '\\')) {
                return null;
            }
            $entry = str_starts_with($target, '/xl/') ? ltrim($target, '/') : 'xl/'.ltrim($target, '/');
            if (1 === preg_match('#(?:^|/)\.\.?(/|$)#', $entry) || !isset($archiveEntries[$entry])) {
                return null;
            }
            $entries[] = $entry;
        }

        return $entries;
    }

    /** @param list<string> $worksheetEntries */
    private function validateWorksheetRows(string $path, array $worksheetEntries): ?PlanImportIssue
    {
        foreach ($worksheetEntries as $entry) {
            $reader = \XMLReader::open('zip://'.$path.'#'.$entry, null, \LIBXML_NONET | \LIBXML_COMPACT);
            if (false === $reader) {
                return new PlanImportIssue(null, 'xlsx_invalid', 'Структура XLSX повреждена.');
            }
            try {
                while ($reader->read()) {
                    if (\XMLReader::ELEMENT !== $reader->nodeType) {
                        continue;
                    }
                    if ('row' === $reader->localName) {
                        $rowIssue = $this->validateWorksheetRow($reader->getAttribute('r'));
                        if (null !== $rowIssue) {
                            return $rowIssue;
                        }
                    }
                    if ('c' === $reader->localName) {
                        $cellIssue = $this->validateWorksheetCell($reader->getAttribute('r'));
                        if (null !== $cellIssue) {
                            return $cellIssue;
                        }
                    }
                }
            } finally {
                $reader->close();
            }
        }

        return null;
    }

    private function validateWorksheetRow(?string $row): ?PlanImportIssue
    {
        if (null === $row || 1 !== preg_match('/^[1-9][0-9]*$/', $row)) {
            return new PlanImportIssue(null, 'xlsx_invalid', 'Структура XLSX повреждена.');
        }
        if (\strlen($row) > 5 || (int) $row > self::MAX_WORKSHEET_ROW) {
            $rowNumber = \strlen($row) < 19 ? (int) $row : null;

            return new PlanImportIssue($rowNumber, 'worksheet_row_limit_exceeded', 'Номер строки листа превышает безопасный лимит 50 000.');
        }

        return null;
    }

    private function validateWorksheetCell(?string $reference): ?PlanImportIssue
    {
        if (null === $reference || 1 !== preg_match('/^([A-Z]+)([1-9][0-9]*)$/', $reference, $matches)) {
            return new PlanImportIssue(null, 'xlsx_invalid', 'Структура XLSX повреждена.');
        }
        $rowIssue = $this->validateWorksheetRow($matches[2]);
        if (null !== $rowIssue) {
            return $rowIssue;
        }
        if (\strlen($matches[1]) > 3) {
            return new PlanImportIssue((int) $matches[2], 'worksheet_column_limit_exceeded', 'Номер колонки листа превышает предел XLSX.');
        }
        $column = 0;
        foreach (str_split($matches[1]) as $letter) {
            $column = $column * 26 + \ord($letter) - 64;
        }
        if ($column > self::MAX_WORKSHEET_COLUMN) {
            return new PlanImportIssue((int) $matches[2], 'worksheet_column_limit_exceeded', 'Номер колонки листа превышает предел XLSX.');
        }

        return null;
    }
}
