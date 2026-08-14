<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Domain;

use App\Ingestion\Domain\MarketplaceExpenseFact;
use App\Ingestion\Domain\OzonAccrualByDayParser;
use App\Shared\Domain\ValueObject\Money;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Парсер проверяется на зафиксированном ответе настоящего кабинета
 * (CLAUDE.md §9: обращений к внешним API в тестах нет).
 */
final class OzonAccrualByDayParserTest extends TestCase
{
    private const string FIXTURE = __DIR__.'/../../../Fixtures/Marketplace/ozon/finance-accrual-by-day-2026-07.json';

    public function testExpensesOfEachCategoryAreParsed(): void
    {
        $facts = $this->parse($this->fixture());

        // Три категории раскладываются в плоские строки: расходы
        // по отправлению, по товару и общие. Общих в этом дне девять
        // из двухсот пятидесяти пяти, и потерять их означало бы занизить
        // издержки на рекламу и хранение.
        $withSku = array_filter($facts, static fn (MarketplaceExpenseFact $f): bool => '' !== $f->marketplaceSku());
        $withoutSku = array_filter($facts, static fn (MarketplaceExpenseFact $f): bool => '' === $f->marketplaceSku());

        self::assertNotSame([], $withSku);
        self::assertCount(9, $withoutSku);
    }

    public function testAmountsKeepTheirSignAndCurrency(): void
    {
        $facts = $this->parse($this->fixture());

        // Расход приходит отрицательным, и таким же обязан остаться:
        // «взять по модулю» здесь означало бы сложить расходы с выручкой
        // и получить завышенную прибыль.
        $negative = array_filter($facts, static fn (MarketplaceExpenseFact $f): bool => $f->amount()->minorAmount() < 0);
        self::assertNotSame([], $negative);

        foreach ($facts as $fact) {
            self::assertSame('RUB', $fact->amount()->currency());
        }
    }

    public function testDecimalStringBecomesMinorUnitsWithoutFloat(): void
    {
        // Суммы приходят строкой: '-19.43' обязано стать -1943 копейками
        // точно, без промежуточного float (CLAUDE.md §3).
        $facts = $this->parse($this->accrual(
            'ITEM',
            '{"fees":[{"sku":308403988,"fees":[{"type_id":1,"accrued":{"amount":"-19.43","currency":"RUB"}}]}]}',
        ));

        self::assertCount(1, $facts);
        self::assertEquals(Money::ofMinor(-1943, 'RUB'), $facts[0]->amount());
        self::assertSame('308403988', $facts[0]->marketplaceSku());
        self::assertSame(1, $facts[0]->feeTypeId());
    }

    public function testSaleCommissionIsNotTurnedIntoAnExpense(): void
    {
        $facts = $this->parse($this->fixture());

        // Выручка и комиссия уже лежат в sales_fact из постингов
        // (ADR-012). Второй экземпляр дал бы двойной счёт, поэтому
        // блок commission пропускается: у продажи в расходы идут только
        // услуги доставки.
        $sale = array_values(array_filter(
            $facts,
            static fn (MarketplaceExpenseFact $f): bool => '64533597-0142-5' === $f->unitNumber(),
        ));

        // Три строки: логистика и доставка до места выдачи из начисления
        // по отправлению плюс эквайринг из начисления по товару — у них
        // общий unit_number. Комиссии продажи (-1263.62 ₽) среди них нет.
        self::assertCount(3, $sale);
        self::assertEqualsCanonicalizing([32, 29, 1], array_map(
            static fn (MarketplaceExpenseFact $f): int => $f->feeTypeId(),
            $sale,
        ));
        self::assertNotContains(-126362, array_map(
            static fn (MarketplaceExpenseFact $f): int => $f->amount()->minorAmount(),
            $sale,
        ));
    }

    public function testKeyIsGluedFromAccrualSkuAndFeeType(): void
    {
        $facts = $this->parse($this->accrual(
            'NON_ITEM',
            null,
            '{"type_id":41,"accrued":{"amount":"-237.93","currency":"RUB"}}',
        ));

        // Ключ склеен по ADR-012, у общих расходов артикул пустой.
        self::assertSame('55153675049||41', $facts[0]->sourceRowIdValue());
    }

    public function testUnknownCategoryStopsTheParse(): void
    {
        // Незнакомая категория — новый вид расхода. Превратить её в ноль
        // строк значит занизить издержки клиента, ничем себя не выдав:
        // ADR-006 требует падать на дрейфе схемы, а не переживать его.
        $this->expectException(\UnexpectedValueException::class);

        $this->parse('{"accruals":[{"accrual_id":1,"date":"2026-07-01","unit_number":"x","accrued_category":"CONTAINER","total_amount":{"amount":"-1","currency":"RUB"}}],"last_id":""}');
    }

    public function testContainerFeesStopTheParse(): void
    {
        // container_fees в снятой фикстуре не заполнен ни разу (ADR-012).
        // Появился — это четвёртый блок расходов внутри знакомой
        // категории, и пропустить его так же нельзя.
        $this->expectException(\UnexpectedValueException::class);

        $this->parse('{"accruals":[{"accrual_id":1,"date":"2026-07-01","unit_number":"x","accrued_category":"POSTING","total_amount":{"amount":"-1","currency":"RUB"},"container_fees":{"fees":[]}}],"last_id":""}');
    }

    public function testResponseWithoutAccrualsIsRejected(): void
    {
        // Ошибка площадки приходит с кодом и сообщением. Пустой день
        // из неё делать нельзя: он выглядел бы как «расходов не было».
        $this->expectException(\UnexpectedValueException::class);

        $this->parse('{"code":8,"message":"You have reached request rate limit per second"}');
    }

    public function testReattributedAccrualChangesTheRowHash(): void
    {
        // Площадка вправе переотнести начисление на другой день, не тронув
        // сумму. Хэш от одной суммы такую правку пропустил бы, и строка
        // осталась бы со старой датой — объяснить клиенту расхождение
        // стало бы нечем (ADR-006).
        $first = $this->parse($this->accrual('ITEM', '{"fees":[{"sku":111,"fees":[{"type_id":1,"accrued":{"amount":"-19.43","currency":"RUB"}}]}]}'));
        $moved = $this->parse(str_replace('2026-07-01', '2026-07-02', $this->accrual('ITEM', '{"fees":[{"sku":111,"fees":[{"type_id":1,"accrued":{"amount":"-19.43","currency":"RUB"}}]}]}')));

        self::assertSame($first[0]->sourceRowIdValue(), $moved[0]->sourceRowIdValue());
        self::assertNotSame($first[0]->rowHash(), $moved[0]->rowHash());
    }

    public function testCursorIsReturnedForPagination(): void
    {
        $parser = new OzonAccrualByDayParser();
        $parsed = $parser->parse(
            '{"accruals":[],"last_id":"next-page"}',
            Uuid::v7(),
            Uuid::v7(),
            Uuid::v7(),
        );

        self::assertSame('next-page', $parsed['lastId']);
        self::assertSame([], $parsed['facts']);
    }

    /**
     * @return list<MarketplaceExpenseFact>
     */
    private function parse(string $rawBody): array
    {
        return (new OzonAccrualByDayParser())->parse($rawBody, Uuid::v7(), Uuid::v7(), Uuid::v7())['facts'];
    }

    private function accrual(string $category, ?string $itemFees = null, ?string $nonItemFee = null): string
    {
        $body = [
            'accrual_id' => 'NON_ITEM' === $category ? 55153675049 : 55129373555,
            'date' => '2026-07-01',
            'unit_number' => '10278453-0923',
            'accrued_category' => $category,
            'total_amount' => ['amount' => '-19.43', 'currency' => 'RUB'],
            'posting' => null,
            'item_fees' => null === $itemFees ? null : json_decode($itemFees, true, flags: \JSON_THROW_ON_ERROR),
            'non_item_fee' => null === $nonItemFee ? null : json_decode($nonItemFee, true, flags: \JSON_THROW_ON_ERROR),
            'container_fees' => null,
        ];

        return json_encode(['accruals' => [$body], 'last_id' => ''], \JSON_THROW_ON_ERROR);
    }

    private function fixture(): string
    {
        $body = file_get_contents(self::FIXTURE);
        self::assertIsString($body);

        return $body;
    }
}
