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
    private const int MAX_WORKSHEET_COLUMN = 16;
    private const int MAX_UNCOMPRESSED_BYTES = 50_000_000;
    private const string SPREADSHEET_NAMESPACE = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const string STRICT_SPREADSHEET_NAMESPACE = 'http://purl.oclc.org/ooxml/spreadsheetml/main';

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
            if ($rowNumber > self::MAX_WORKSHEET_ROW) {
                return new XlsxDailyPlanReadResult([], [new PlanImportIssue($rowNumber, 'worksheet_row_limit_exceeded', 'Номер строки листа превышает безопасный лимит 50 000.')]);
            }
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
        $previousErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $workbook = @\XMLReader::open('zip://'.$path.'#xl/workbook.xml', null, \LIBXML_NONET | \LIBXML_COMPACT);
        if (false === $workbook) {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);

            return null;
        }
        try {
            while (@$workbook->read()) {
                if (\XMLReader::ELEMENT === $workbook->nodeType && 'sheet' === $workbook->localName
                    && \in_array($workbook->namespaceURI, [self::SPREADSHEET_NAMESPACE, self::STRICT_SPREADSHEET_NAMESPACE], true)) {
                    // OpenSpout 5.11.3 reads this exact QName in SheetManager.
                    // Reject its unsupported variant here instead of letting
                    // the vendor assertion escape as HTTP 500.
                    $id = $workbook->getAttribute('r:id');
                    if (null === $id || '' === $id) {
                        return null;
                    }
                    $sheetIds[] = $id;
                }
            }
            if ([] !== libxml_get_errors()) {
                return null;
            }
        } finally {
            $workbook->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
        if ([] === $sheetIds) {
            return null;
        }

        $targets = [];
        $previousErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $relationships = @\XMLReader::open('zip://'.$path.'#xl/_rels/workbook.xml.rels', null, \LIBXML_NONET | \LIBXML_COMPACT);
        if (false === $relationships) {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);

            return null;
        }
        try {
            while (@$relationships->read()) {
                if (\XMLReader::ELEMENT !== $relationships->nodeType || 'Relationship' !== $relationships->localName) {
                    continue;
                }
                $id = $relationships->getAttribute('Id');
                $target = $relationships->getAttribute('Target');
                if (null !== $id && null !== $target) {
                    $targets[$id] = $target;
                }
            }
            if ([] !== libxml_get_errors()) {
                return null;
            }
        } finally {
            $relationships->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
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
            $previousErrors = libxml_use_internal_errors(true);
            libxml_clear_errors();
            $reader = @\XMLReader::open('zip://'.$path.'#'.$entry, null, \LIBXML_NONET | \LIBXML_COMPACT);
            if (false === $reader) {
                libxml_clear_errors();
                libxml_use_internal_errors($previousErrors);

                return new PlanImportIssue(null, 'xlsx_invalid', 'Структура XLSX повреждена.');
            }
            $currentRow = 0;
            $lastColumn = 0;
            $worksheetSeen = false;
            try {
                while (@$reader->read()) {
                    if (\XMLReader::ELEMENT !== $reader->nodeType) {
                        continue;
                    }
                    if ('worksheet' === $reader->localName && !$worksheetSeen) {
                        if (!\in_array($reader->namespaceURI, [self::SPREADSHEET_NAMESPACE, self::STRICT_SPREADSHEET_NAMESPACE], true)) {
                            return new PlanImportIssue(null, 'xlsx_invalid', 'Структура XLSX повреждена.');
                        }
                        $worksheetSeen = true;

                        continue;
                    }
                    if ('row' === $reader->localName) {
                        $row = $reader->getAttribute('r');
                        $currentRow = null === $row ? $currentRow + 1 : (int) $row;
                        $lastColumn = 0;
                        $rowIssue = $this->validateWorksheetRow($row, $currentRow);
                        if (null !== $rowIssue) {
                            return $rowIssue;
                        }
                        $spans = $reader->getAttribute('spans');
                        if (null !== $spans && 1 === preg_match('/^[1-9][0-9]*:([1-9][0-9]*)$/', $spans, $matches)
                            && (\strlen($matches[1]) > 5 || (int) $matches[1] > self::MAX_WORKSHEET_COLUMN)) {
                            return new PlanImportIssue($currentRow, 'worksheet_column_limit_exceeded', 'Номер колонки листа превышает безопасный лимит 16.');
                        }
                    }
                    if ('c' === $reader->localName) {
                        $reference = $reader->getAttribute('r');
                        $cellIssue = $this->validateWorksheetCell($reference, $currentRow, $lastColumn + 1);
                        if (null !== $cellIssue) {
                            return $cellIssue;
                        }
                        $lastColumn = null === $reference ? $lastColumn + 1 : $this->columnNumber($reference);
                    }
                }
                if (!$worksheetSeen || [] !== libxml_get_errors()) {
                    return new PlanImportIssue(null, 'xlsx_invalid', 'Структура XLSX повреждена.');
                }
            } finally {
                $reader->close();
                libxml_clear_errors();
                libxml_use_internal_errors($previousErrors);
            }
        }

        return null;
    }

    private function validateWorksheetRow(?string $row, int $implicitRow): ?PlanImportIssue
    {
        if (null !== $row && 1 !== preg_match('/^[1-9][0-9]*$/', $row)) {
            return new PlanImportIssue(null, 'xlsx_invalid', 'Структура XLSX повреждена.');
        }
        $normalized = $row ?? (string) $implicitRow;
        if (\strlen($normalized) > 5 || (int) $normalized > self::MAX_WORKSHEET_ROW) {
            $rowNumber = \strlen($normalized) < 19 ? (int) $normalized : null;

            return new PlanImportIssue($rowNumber, 'worksheet_row_limit_exceeded', 'Номер строки листа превышает безопасный лимит 50 000.');
        }

        return null;
    }

    private function validateWorksheetCell(?string $reference, int $currentRow, int $implicitColumn): ?PlanImportIssue
    {
        if (null === $reference) {
            if ($currentRow < 1) {
                return new PlanImportIssue(null, 'xlsx_invalid', 'Структура XLSX повреждена.');
            }

            return $implicitColumn > self::MAX_WORKSHEET_COLUMN
                ? new PlanImportIssue($currentRow, 'worksheet_column_limit_exceeded', 'Номер колонки листа превышает безопасный лимит 16.')
                : null;
        }
        if (1 !== preg_match('/^([A-Z]+)([1-9][0-9]*)$/', $reference, $matches)) {
            return new PlanImportIssue(null, 'xlsx_invalid', 'Структура XLSX повреждена.');
        }
        $rowIssue = $this->validateWorksheetRow($matches[2], (int) $matches[2]);
        if (null !== $rowIssue) {
            return $rowIssue;
        }
        if (\strlen($matches[1]) > 3) {
            return new PlanImportIssue((int) $matches[2], 'worksheet_column_limit_exceeded', 'Номер колонки листа превышает безопасный лимит 16.');
        }
        $column = $this->columnNumber($reference);
        if ($column > self::MAX_WORKSHEET_COLUMN) {
            return new PlanImportIssue((int) $matches[2], 'worksheet_column_limit_exceeded', 'Номер колонки листа превышает безопасный лимит 16.');
        }

        return null;
    }

    private function columnNumber(string $reference): int
    {
        $column = 0;
        for ($index = 0; isset($reference[$index]) && !ctype_digit($reference[$index]); ++$index) {
            $column = $column * 26 + \ord($reference[$index]) - 64;
        }

        return $column;
    }
}
