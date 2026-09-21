<?php

declare(strict_types=1);

namespace App\Tests\Unit\Planning;

use App\Planning\Infrastructure\Import\XlsxDailyPlanReader;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Row;
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
            Row::fromValues(['SKU-2', new \DateTimeImmutable('2026-09-23 23:30:00 UTC'), 0]),
            Row::fromValues(['SKU-3', '2028-02-29', 1]),
        ]);

        $result = (new XlsxDailyPlanReader())->read($file);

        self::assertSame([], $result->issues);
        self::assertCount(3, $result->rows);
        self::assertSame([2, 'SKU-1', '2026-09-22', 12], [$result->rows[0]->rowNumber, $result->rows[0]->marketplaceSku, $result->rows[0]->businessDate, $result->rows[0]->quantity]);
        self::assertSame([3, 'SKU-2', '2026-09-23', 0], [$result->rows[1]->rowNumber, $result->rows[1]->marketplaceSku, $result->rows[1]->businessDate, $result->rows[1]->quantity]);
        self::assertSame('2028-02-29', $result->rows[2]->businessDate);
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

    public function testRejectsFormulaAndSecondSheet(): void
    {
        $formula = $this->xlsx([
            Row::fromValues(['SKU', 'Дата', 'План, шт.']),
            new Row([new FormulaCell('"SKU-1"'), ...Row::fromValues(['2026-09-22', 2])->cells]),
        ]);
        $formulaResult = (new XlsxDailyPlanReader())->read($formula);
        self::assertSame('formula_forbidden', $formulaResult->issues[0]->code);

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

        self::assertSame('headers_invalid', (new XlsxDailyPlanReader())->read($file)->issues[0]->code);
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

        $bomb = tempnam(sys_get_temp_dir(), 'planning-bomb-');
        self::assertIsString($bomb);
        $this->files[] = $bomb;
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($bomb, \ZipArchive::OVERWRITE));
        $zip->addFromString('xl/bomb.xml', str_repeat('A', 2_000_000));
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
        for ($row = 1; $row < XlsxDailyPlanReader::MAX_ROWS; ++$row) {
            $writer->addRow(Row::fromValues(['SKU-'.$row, '2026-09-22', 1]));
        }
        $writer->addRow(Row::fromValues(['SKU-LAST', 'invalid-date', 1]));
        $writer->close();

        $result = (new XlsxDailyPlanReader())->read($file);

        self::assertSame('date_invalid', $result->issues[0]->code);
        self::assertSame(XlsxDailyPlanReader::MAX_ROWS + 1, $result->issues[0]->rowNumber);
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
