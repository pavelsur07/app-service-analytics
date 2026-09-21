<?php

declare(strict_types=1);

namespace App\Tests\Unit\Planning;

use App\Planning\Domain\DailyPlan;
use App\Planning\Infrastructure\Import\XlsxDailyPlanReader;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\TestCase;

final class XlsxDailyPlanReaderTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function testReadsAndNormalizesValidRows(): void
    {
        $file = $this->xlsx([
            Row::fromValues(['SKU', 'Дата', 'План, шт.']),
            Row::fromValues(['SKU-1', '2026-09-22', 12]),
            new Row([
                Cell::fromValue('SKU-2'),
                Cell::fromValue(new \DateTimeImmutable('2026-09-23 23:30:00 UTC'), new Style(format: 'yyyy-mm-dd')),
                Cell::fromValue(0),
            ]),
            Row::fromValues(['SKU-3', '2028-02-29', 1]),
        ]);

        $result = (new XlsxDailyPlanReader())->read($file);

        self::assertSame([], $result->issues);
        self::assertCount(3, $result->rows);
        self::assertSame([2, 'SKU-1', '2026-09-22', 12], [$result->rows[0]->rowNumber, $result->rows[0]->marketplaceSku, $result->rows[0]->businessDate, $result->rows[0]->quantity]);
        self::assertSame([3, 'SKU-2', '2026-09-23', 0], [$result->rows[1]->rowNumber, $result->rows[1]->marketplaceSku, $result->rows[1]->businessDate, $result->rows[1]->quantity]);
        self::assertSame('2028-02-29', $result->rows[2]->businessDate);
    }

    public function testNormalizesNumericSkuAndRejectsGeneralNumericDate(): void
    {
        $file = $this->xlsx([
            Row::fromValues(['SKU', 'Дата', 'План, шт.']),
            Row::fromValues([1_727_916_074, '2026-09-22', 1]),
            Row::fromValues(['SKU-2', 46_287, 2]),
        ]);

        $result = (new XlsxDailyPlanReader())->read($file);

        self::assertSame('1727916074', $result->rows[0]->marketplaceSku);
        self::assertSame([[3, 'date_invalid']], array_map(static fn ($issue): array => [$issue->rowNumber, $issue->code], $result->issues));
    }

    public function testCollectsAllRowErrorsAndDuplicateKeys(): void
    {
        $file = $this->xlsx([
            Row::fromValues(['SKU', 'Дата', 'План, шт.']),
            Row::fromValues(['SKU-1', '2026-02-30', -1]),
            Row::fromValues(['SKU-2', '2026-09-22', 1.5]),
            Row::fromValues(['SKU-2', '2026-09-22', 2]),
        ]);

        $result = (new XlsxDailyPlanReader())->read($file);

        self::assertSame([], $result->rows);
        self::assertSame(
            [[2, 'date_invalid'], [2, 'quantity_invalid'], [3, 'quantity_invalid'], [4, 'duplicate_key']],
            array_map(static fn ($issue): array => [$issue->rowNumber, $issue->code], $result->issues),
        );
    }

    public function testRejectsQuantityOutsideIntegerRangeBeforeCasting(): void
    {
        $file = $this->xlsx([
            Row::fromValues(['SKU', 'Дата', 'План, шт.']),
            Row::fromValues(['SKU-1', '2026-09-22', 1.0E+35]),
            Row::fromValues(['SKU-2', '2026-09-22', (float) DailyPlan::MAX_QUANTITY + 1]),
        ]);

        $result = (new XlsxDailyPlanReader())->read($file);

        self::assertSame([], $result->rows);
        self::assertSame(
            [[2, 'quantity_invalid'], [3, 'quantity_invalid']],
            array_map(static fn ($issue): array => [$issue->rowNumber, $issue->code], $result->issues),
        );
    }

    public function testRejectsFormulaAndSecondSheet(): void
    {
        $formula = $this->xlsx([
            Row::fromValues(['SKU', 'Дата', 'План, шт.']),
            new Row([...Row::fromValues(['UNKNOWN', 'invalid-date'])->cells, new FormulaCell('1+1')]),
        ]);
        $formulaResult = (new XlsxDailyPlanReader())->read($formula);
        self::assertSame(['formula_forbidden', 'date_invalid', 'quantity_invalid'], array_map(static fn ($issue): string => $issue->code, $formulaResult->issues));
        self::assertSame('UNKNOWN', $formulaResult->skuReferences[0]->marketplaceSku);

        $secondSheet = $this->xlsx([Row::fromValues(['SKU', 'Дата', 'План, шт.'])], true);
        $sheetResult = (new XlsxDailyPlanReader())->read($secondSheet);
        self::assertSame('sheet_count_invalid', $sheetResult->issues[0]->code);
    }

    public function testRejectsUnexpectedFourthColumn(): void
    {
        $file = $this->xlsx([
            Row::fromValues(['SKU', 'Дата', 'План, шт.']),
            Row::fromValues(['SKU-1', '2026-09-22', 12, 'unexpected']),
        ]);

        self::assertSame('columns_invalid', (new XlsxDailyPlanReader())->read($file)->issues[0]->code);
    }

    public function testAllowsEmptyTrailingHeaderCell(): void
    {
        $file = $this->xlsx([
            Row::fromValues(['SKU', 'Дата', 'План, шт.', null]),
            Row::fromValues(['SKU-1', '2026-09-22', 12]),
        ]);

        self::assertSame([], (new XlsxDailyPlanReader())->read($file)->issues);
    }

    public function testReadsRowsAndCellsWithoutOptionalReferences(): void
    {
        $file = $this->xlsx([
            Row::fromValues(['SKU', 'Дата', 'План, шт.']),
            Row::fromValues(['SKU-1', '2026-09-22', 12]),
        ]);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($file));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        self::assertIsString($sheet);
        $sheet = preg_replace('/(<(?:row|c)) r="[^"]+"/', '$1', $sheet);
        self::assertIsString($sheet);
        self::assertTrue($zip->addFromString('xl/worksheets/sheet1.xml', $sheet));
        $zip->close();

        $result = (new XlsxDailyPlanReader())->read($file);

        self::assertSame([], $result->issues);
        self::assertSame([2, 'SKU-1', '2026-09-22', 12], [$result->rows[0]->rowNumber, $result->rows[0]->marketplaceSku, $result->rows[0]->businessDate, $result->rows[0]->quantity]);
    }

    public function testDamagedWorkbookXmlReturnsControlledIssue(): void
    {
        $file = $this->xlsx([
            Row::fromValues(['SKU', 'Дата', 'План, шт.']),
            Row::fromValues(['SKU-1', '2026-09-22', 12]),
        ]);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($file));
        self::assertTrue($zip->addFromString('xl/workbook.xml', '<workbook><broken>'));
        $zip->close();

        $result = (new XlsxDailyPlanReader())->read($file);

        self::assertSame('xlsx_invalid', $result->issues[0]->code);
    }

    public function testRejectsWorkbookWithDamagedTailAfterSheetDeclaration(): void
    {
        $file = $this->xlsx([
            Row::fromValues(['SKU', 'Дата', 'План, шт.']),
            Row::fromValues(['SKU-1', '2026-09-22', 12]),
        ]);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($file));
        $workbook = $zip->getFromName('xl/workbook.xml');
        self::assertIsString($workbook);
        $workbook = str_replace('</workbook>', '<broken></workbook>', $workbook);
        self::assertTrue($zip->addFromString('xl/workbook.xml', $workbook));
        $zip->close();

        self::assertSame('xlsx_invalid', (new XlsxDailyPlanReader())->read($file)->issues[0]->code);
    }

    public function testReturnsControlledIssueForRelationshipPrefixUnsupportedByOpenSpout(): void
    {
        $file = $this->xlsx([
            Row::fromValues(['SKU', 'Дата', 'План, шт.']),
            Row::fromValues(['SKU-1', '2026-09-22', 12]),
        ]);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($file));
        $workbook = $zip->getFromName('xl/workbook.xml');
        self::assertIsString($workbook);
        $workbook = str_replace(['xmlns:r=', ' r:id='], ['xmlns:rel=', ' rel:id='], $workbook);
        self::assertTrue($zip->addFromString('xl/workbook.xml', $workbook));
        $zip->close();

        self::assertSame('xlsx_invalid', (new XlsxDailyPlanReader())->read($file)->issues[0]->code);
    }

    public function testRejectsUnsafeWorksheetRowBeforeOpenSpoutExpandsTheGap(): void
    {
        $file = $this->xlsx([
            Row::fromValues(['SKU', 'Дата', 'План, шт.']),
            Row::fromValues(['SKU-1', '2026-09-22', 12]),
        ]);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($file));
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        self::assertIsString($xml);
        $xml = str_replace('<row r="2"', '<row r="'.(XlsxDailyPlanReader::MAX_WORKSHEET_ROW + 1).'"', $xml);
        self::assertTrue($zip->addFromString('xl/worksheets/sheet1.xml', $xml));
        $zip->close();

        $issue = (new XlsxDailyPlanReader())->read($file)->issues[0];

        self::assertSame('worksheet_row_limit_exceeded', $issue->code);
        self::assertSame(XlsxDailyPlanReader::MAX_WORKSHEET_ROW + 1, $issue->rowNumber);
    }

    public function testRejectsUnsafeRowAtWorksheetPathSelectedByRelationship(): void
    {
        $file = $this->xlsx([
            Row::fromValues(['SKU', 'Дата', 'План, шт.']),
            Row::fromValues(['SKU-1', '2026-09-22', 12]),
        ]);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($file));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $relationships = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $contentTypes = $zip->getFromName('[Content_Types].xml');
        self::assertIsString($sheet);
        self::assertIsString($relationships);
        self::assertIsString($contentTypes);
        $sheet = str_replace('<row r="2"', '<row r="'.(XlsxDailyPlanReader::MAX_WORKSHEET_ROW + 1).'"', $sheet);
        self::assertTrue($zip->addFromString('xl/custom-sheet.xml', $sheet));
        self::assertTrue($zip->addFromString('xl/_rels/workbook.xml.rels', str_replace('worksheets/sheet1.xml', 'custom-sheet.xml', $relationships)));
        self::assertTrue($zip->addFromString('[Content_Types].xml', str_replace('/xl/worksheets/sheet1.xml', '/xl/custom-sheet.xml', $contentTypes)));
        self::assertTrue($zip->deleteName('xl/worksheets/sheet1.xml'));
        $zip->close();

        $issue = (new XlsxDailyPlanReader())->read($file)->issues[0];

        self::assertSame('worksheet_row_limit_exceeded', $issue->code);
        self::assertSame(XlsxDailyPlanReader::MAX_WORKSHEET_ROW + 1, $issue->rowNumber);
    }

    public function testRejectsCellBeyondSafeImportColumnBeforeOpenSpoutAllocatesIt(): void
    {
        $file = $this->xlsx([
            Row::fromValues(['SKU', 'Дата', 'План, шт.']),
            Row::fromValues(['SKU-1', '2026-09-22', 12]),
        ]);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($file));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        self::assertIsString($sheet);
        self::assertStringContainsString('r="A2"', $sheet);
        self::assertTrue($zip->addFromString('xl/worksheets/sheet1.xml', str_replace('r="A2"', 'r="Q2"', $sheet)));
        $zip->close();

        $issue = (new XlsxDailyPlanReader())->read($file)->issues[0];

        self::assertSame('worksheet_column_limit_exceeded', $issue->code);
        self::assertSame(2, $issue->rowNumber);
    }

    public function testRejectsWorksheetOutsideSpreadsheetNamespace(): void
    {
        $file = $this->xlsx([
            Row::fromValues(['SKU', 'Дата', 'План, шт.']),
            Row::fromValues(['SKU-1', '2026-09-22', 12]),
        ]);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($file));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        self::assertIsString($sheet);
        $sheet = str_replace(
            'http://schemas.openxmlformats.org/spreadsheetml/2006/main',
            'https://example.test/untrusted-spreadsheet',
            $sheet,
        );
        self::assertTrue($zip->addFromString('xl/worksheets/sheet1.xml', $sheet));
        $zip->close();

        self::assertSame('xlsx_invalid', (new XlsxDailyPlanReader())->read($file)->issues[0]->code);
    }

    public function testIssueUsesWorksheetRowNumberAfterEmptyRow(): void
    {
        $file = $this->xlsx([
            Row::fromValues(['SKU', 'Дата', 'План, шт.']),
            Row::fromValues([]),
            Row::fromValues(['SKU-1', 'invalid-date', 1]),
        ]);

        $issue = (new XlsxDailyPlanReader())->read($file)->issues[0];

        self::assertSame('date_invalid', $issue->code);
        self::assertSame(3, $issue->rowNumber);
    }

    public function testRejectsNoDataTooManyRowsAndUnsafeArchives(): void
    {
        $headerOnly = $this->xlsx([Row::fromValues(['SKU', 'Дата', 'План, шт.'])]);
        self::assertSame('data_rows_required', (new XlsxDailyPlanReader())->read($headerOnly)->issues[0]->code);

        $tooMany = tempnam(sys_get_temp_dir(), 'planning-many-');
        self::assertIsString($tooMany);
        $this->files[] = $tooMany;
        $writer = new Writer();
        $writer->openToFile($tooMany);
        $writer->addRow(Row::fromValues(['SKU', 'Дата', 'План, шт.']));
        for ($row = 0; $row <= XlsxDailyPlanReader::MAX_ROWS; ++$row) {
            $writer->addRow(Row::fromValues(['SKU-'.$row, '2026-09-22', 1]));
        }
        $writer->close();
        self::assertSame('row_limit_exceeded', (new XlsxDailyPlanReader())->read($tooMany)->issues[0]->code);

        $oversized = tempnam(sys_get_temp_dir(), 'planning-large-');
        self::assertIsString($oversized);
        $this->files[] = $oversized;
        file_put_contents($oversized, str_repeat('x', XlsxDailyPlanReader::MAX_FILE_BYTES + 1));
        self::assertSame('file_size_invalid', (new XlsxDailyPlanReader())->read($oversized)->issues[0]->code);

        $bombSource = tempnam(sys_get_temp_dir(), 'planning-bomb-source-');
        self::assertIsString($bombSource);
        $this->files[] = $bombSource;
        $handle = fopen($bombSource, 'w');
        self::assertIsResource($handle);
        for ($chunk = 0; $chunk < 51; ++$chunk) {
            self::assertSame(1_000_000, fwrite($handle, str_repeat('A', 1_000_000)));
        }
        fclose($handle);

        $bomb = tempnam(sys_get_temp_dir(), 'planning-bomb-');
        self::assertIsString($bomb);
        $this->files[] = $bomb;
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($bomb, \ZipArchive::OVERWRITE));
        self::assertTrue($zip->addFile($bombSource, 'xl/bomb.xml'));
        $zip->close();
        self::assertSame('archive_limit_exceeded', (new XlsxDailyPlanReader())->read($bomb)->issues[0]->code);
    }

    public function testReportsInvalidLastRowAtMaximumAllowedSize(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'planning-max-');
        self::assertIsString($file);
        $this->files[] = $file;
        $writer = new Writer();
        $writer->openToFile($file);
        $writer->addRow(Row::fromValues(['SKU', 'Дата', 'План, шт.']));
        $writer->addRow(Row::fromValues([]));
        for ($row = 1; $row < XlsxDailyPlanReader::MAX_ROWS; ++$row) {
            $writer->addRow(Row::fromValues(['SKU-'.$row, '2026-09-22', 1]));
        }
        $writer->addRow(Row::fromValues(['SKU-LAST', 'invalid-date', 1]));
        $writer->close();

        $result = (new XlsxDailyPlanReader())->read($file);

        self::assertSame('date_invalid', $result->issues[0]->code);
        self::assertSame(XlsxDailyPlanReader::MAX_ROWS + 2, $result->issues[0]->rowNumber);
    }

    /** @param list<Row> $rows */
    private function xlsx(array $rows, bool $secondSheet = false): string
    {
        $file = tempnam(sys_get_temp_dir(), 'planning-xlsx-');
        self::assertIsString($file);
        $this->files[] = $file;
        $writer = new Writer();
        $writer->openToFile($file);
        $writer->addRows($rows);
        if ($secondSheet) {
            $writer->addNewSheetAndMakeItCurrent();
            $writer->addRow(Row::fromValues(['other']));
        }
        $writer->close();

        return $file;
    }
}
