<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Ui\Request\ListingCostRequest;
use PHPUnit\Framework\TestCase;

/**
 * Разбор тела запроса — здесь, а не через HTTP: §9 запрещает тестировать
 * контроллеры, а проверяемое живёт в DTO и БД не требует.
 */
final class ListingCostRequestTest extends TestCase
{
    public function testFractionalAmountIsRejected(): void
    {
        // Дробное число в JSON — это double, и 420.10 приезжает как
        // 420.09999999999997. ADR-004 запрещает float в денежных
        // вычислениях, и незаметнее всего это нарушается ровно здесь.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unit_cost_minor_required');

        ListingCostRequest::fromJson($this->body(['unitCostMinor' => 420.10]));
    }

    public function testCurrencyHasNoDefault(): void
    {
        // Подставить RUB значило бы решить за клиента, в чём он считает
        // закупку (ADR-004).
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('currency_required');

        ListingCostRequest::fromJson($this->body(['currency' => null]));
    }

    public function testNegativeAmountIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unit_cost_negative');

        ListingCostRequest::fromJson($this->body(['unitCostMinor' => -1]));
    }

    public function testImpossibleDateIsRejected(): void
    {
        // 30 февраля разбирается и молча переезжает на 2 марта —
        // то есть цена начала бы действовать не с той даты.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('effective_from_invalid');

        ListingCostRequest::fromJson($this->body(['effectiveFrom' => '2026-02-30']));
    }

    public function testValidBodyIsParsed(): void
    {
        $request = ListingCostRequest::fromJson($this->body([]));

        self::assertSame(42_000, $request->unitCostMinor);
        self::assertSame('RUB', $request->currency);
        self::assertSame('2026-07-01', $request->effectiveFrom->format('Y-m-d'));
    }

    public function testCorrectionWithoutVersionIsRejected(): void
    {
        // ADR-008: принимать изменение «без версии» как безусловное
        // правила прямо запрещают.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('version_required');

        ListingCostRequest::correctionFromJson(
            json_encode(['unitCostMinor' => 42_000, 'currency' => 'RUB'], \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function body(array $overrides): string
    {
        return json_encode([
            'marketplaceAccountId' => '019ffe00-0000-7000-8000-000000000001',
            'marketplaceSku' => '220280923',
            'effectiveFrom' => '2026-07-01',
            'unitCostMinor' => 42_000,
            'currency' => 'RUB',
            ...$overrides,
        ], \JSON_THROW_ON_ERROR);
    }
}
