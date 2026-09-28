<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Domain;

use App\Ingestion\Domain\MarketplaceExpenseFact;
use App\Ingestion\Domain\OzonAccrualByDayParser;
use App\Ingestion\Domain\OzonFeeTypeNames;
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

    public function testSaleGivesRevenueAndCommissionRows(): void
    {
        $facts = $this->parse($this->fixture());

        // Продажа 64533597-0142-5: 2747 − 1263,62 − 76,85 = 1406,53.
        // Выручка и вознаграждение — строками начисления рядом
        // с логистикой (ADR-036), эквайринг — отдельное начисление
        // по товару с тем же unit_number.
        $sale = array_values(array_filter(
            $facts,
            static fn (MarketplaceExpenseFact $f): bool => '64533597-0142-5' === $f->unitNumber(),
        ));

        $byType = [];
        foreach ($sale as $fact) {
            $byType[$fact->feeTypeId()] = $fact->amount()->minorAmount();
        }
        ksort($byType);

        self::assertSame([
            OzonFeeTypeNames::REVENUE => 274700,
            1 => -2336,
            29 => -785,
            32 => -6900,
            OzonFeeTypeNames::SALE_COMMISSION => -126362,
        ], $byType);
    }

    public function testReturnIsNotSkippedSilently(): void
    {
        $facts = $this->parse($this->fixture());

        // Возврат приходит начислением без услуг доставки — только блок
        // commission. Раньше он давал ноль строк без ошибки; теперь это
        // выручка со знаком минус и возврат вознаграждения (ADR-036).
        $return = array_values(array_filter(
            $facts,
            static fn (MarketplaceExpenseFact $f): bool => '46205549-0525-1' === $f->unitNumber(),
        ));

        $byType = [];
        foreach ($return as $fact) {
            $byType[$fact->feeTypeId()] = $fact->amount()->minorAmount();
        }

        self::assertSame(-240200, $byType[OzonFeeTypeNames::REVENUE] ?? null);
        self::assertSame(110492, $byType[OzonFeeTypeNames::SALE_COMMISSION] ?? null);
    }

    public function testEachRevenueRowIsOneUnit(): void
    {
        $facts = $this->parse($this->fixture());

        // 31 блок commission за день: 30 продаж и один возврат.
        $revenue = array_map(
            static fn (MarketplaceExpenseFact $f): int => $f->amount()->minorAmount(),
            array_filter($facts, static fn (MarketplaceExpenseFact $f): bool => OzonFeeTypeNames::REVENUE === $f->feeTypeId()),
        );

        self::assertCount(30, array_filter($revenue, static fn (int $amount): bool => $amount > 0));
        self::assertCount(1, array_filter($revenue, static fn (int $amount): bool => $amount < 0));
    }

    public function testRowsOfEveryAccrualAddUpToItsTotal(): void
    {
        $facts = $this->parse($this->fixture());
        $decoded = json_decode($this->fixture(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['accruals']);

        // Сумма всех строк дня равна сумме total_amount всех начислений:
        // ни одной копейки мимо разбора.
        $expected = 0;
        foreach ($decoded['accruals'] as $accrual) {
            self::assertIsArray($accrual);
            self::assertIsArray($accrual['total_amount']);
            self::assertIsString($accrual['total_amount']['amount']);
            // Строкой, без float (CLAUDE.md §3): «-5.8» — это -580.
            [$whole, $fraction] = array_pad(explode('.', $accrual['total_amount']['amount'], 2), 2, '');
            $minor = (int) ltrim($whole, '-') * 100 + (int) str_pad($fraction, 2, '0');
            $expected += str_starts_with($whole, '-') ? -$minor : $minor;
        }

        self::assertSame($expected, array_sum(array_map(
            static fn (MarketplaceExpenseFact $f): int => $f->amount()->minorAmount(),
            $facts,
        )));
    }

    public function testUnbalancedAccrualStopsTheParse(): void
    {
        // Начисление по отправлению без услуг и без commission, но с суммой:
        // часть денег разбор не понял. Ноль строк без ошибки — ровно то,
        // что раньше происходило с возвратом (ADR-036 п. 5).
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('разбор не понял');

        $this->parse('{"accruals":[{"accrual_id":1,"date":"2026-07-01","unit_number":"x","accrued_category":"POSTING","total_amount":{"amount":"-1297.08","currency":"RUB"},"posting":{"delivery_schema":"Fbo","products":[{"sku":1,"delivery":null,"commission":null}]},"item_fees":null,"non_item_fee":null,"container_fees":null}],"last_id":""}');
    }

    public function testSameSkuTwiceInOneAccrualStopsTheParse(): void
    {
        // Две записи products одного артикула — несколько штук одной
        // продажи. Ключ строки их не различит, и одна штука пропала бы.
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('дважды');

        $product = '{"sku":1,"delivery":null,"commission":{"seller_price":{"amount":"2000","currency":"RUB"},"sale_amount":{"amount":"2000","currency":"RUB"},"sale_commission":{"amount":"-1200","currency":"RUB"}}}';
        $this->parse('{"accruals":[{"accrual_id":1,"date":"2026-07-01","unit_number":"x","accrued_category":"POSTING","total_amount":{"amount":"1600","currency":"RUB"},"posting":{"delivery_schema":"Fbo","products":['.$product.','.$product.']},"item_fees":null,"non_item_fee":null,"container_fees":null}],"last_id":""}');
    }

    public function testRevenueOfSeveralUnitsStopsTheParse(): void
    {
        // Количества в ответе нет, штука — строка выручки. sale_amount,
        // не равная цене одной штуки, означает строку на несколько —
        // посчитать её одной значило бы ошибиться в количестве.
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('seller_price');

        $this->parse('{"accruals":[{"accrual_id":1,"date":"2026-07-01","unit_number":"x","accrued_category":"POSTING","total_amount":{"amount":"2800","currency":"RUB"},"posting":{"delivery_schema":"Fbo","products":[{"sku":1,"delivery":null,"commission":{"seller_price":{"amount":"2000","currency":"RUB"},"sale_amount":{"amount":"4000","currency":"RUB"},"sale_commission":{"amount":"-1200","currency":"RUB"}}}]},"item_fees":null,"non_item_fee":null,"container_fees":null}],"last_id":""}');
    }

    public function testKeyIsGluedFromAccrualSkuAndFeeType(): void
    {
        $facts = $this->parse($this->accrual(
            'NON_ITEM',
            null,
            '{"type_id":41,"accrued":{"amount":"-237.93","currency":"RUB"}}',
            '-237.93',
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

    private function accrual(string $category, ?string $itemFees = null, ?string $nonItemFee = null, string $total = '-19.43'): string
    {
        $body = [
            'accrual_id' => 'NON_ITEM' === $category ? 55153675049 : 55129373555,
            'date' => '2026-07-01',
            'unit_number' => '10278453-0923',
            'accrued_category' => $category,
            'total_amount' => ['amount' => $total, 'currency' => 'RUB'],
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
