<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\ValueObject;

use App\Shared\Domain\ValueObject\AllocationRule;
use App\Shared\Domain\ValueObject\Money;
use PHPUnit\Framework\TestCase;

/**
 * Обязательное покрытие CLAUDE.md §9: денежная арифметика и аллокация.
 *
 * Главное свойство здесь одно и проверяется в каждом случае: сумма частей
 * равна исходной величине. Копейка, потерянная при делении, — это копейка
 * расхождения с отчётом площадки, а клиент сверяет цифры именно с ним.
 */
final class MoneyTest extends TestCase
{
    public function testAllocationDistributesRemainderAndSumsBackToTheWhole(): void
    {
        $total = Money::ofMinor(10_000, 'RUB');

        $parts = $total->allocate([1, 1, 1], AllocationRule::RemainderToFirst);

        self::assertSame([3334, 3333, 3333], self::minorAmounts($parts));
        self::assertSame($total->minorAmount(), array_sum(self::minorAmounts($parts)));

        foreach ($parts as $part) {
            self::assertSame('RUB', $part->currency());
        }
    }

    public function testAllocationRespectsUnequalRatios(): void
    {
        // Аллокация расходов по долям — основной случай ADR-004:
        // логистика на отправление делится между товарами по их цене,
        // а не поровну.
        $parts = Money::ofMinor(1_000, 'RUB')->allocate([7, 3], AllocationRule::RemainderToFirst);

        self::assertSame([700, 300], self::minorAmounts($parts));
    }

    public function testRemainderGoesToTheFirstReceivers(): void
    {
        // 100 на троих: 34/33/33, а не 33/33/34 и не 33/33/33 с потерей.
        // Правило названо RemainderToFirst — тест это и закрепляет,
        // иначе смена режима brick/money прошла бы незамеченной.
        $parts = Money::ofMinor(100, 'RUB')->allocate([1, 1, 1], AllocationRule::RemainderToFirst);

        self::assertSame([34, 33, 33], self::minorAmounts($parts));
    }

    public function testNegativeAmountIsAllocatedWithoutLosingKopecks(): void
    {
        // Комиссии площадки отрицательные — в фикстуре Ozon
        // commission_amount_minor = -32 400. Деление отрицательной суммы
        // не отдельный экзотический случай, а половина того, что вообще
        // делится, и сумма частей обязана сходиться так же точно.
        $total = Money::ofMinor(-100, 'RUB');

        $parts = $total->allocate([1, 1, 1], AllocationRule::RemainderToFirst);

        self::assertSame($total->minorAmount(), array_sum(self::minorAmounts($parts)));
        self::assertCount(3, $parts);
    }

    public function testSingleRatioReturnsTheWholeAmount(): void
    {
        $parts = Money::ofMinor(999, 'RUB')->allocate([1], AllocationRule::RemainderToFirst);

        self::assertSame([999], self::minorAmounts($parts));
    }

    public function testZeroRatioReceivesNothingAndDoesNotSwallowTheRemainder(): void
    {
        // Товар с нулевой ценой в отправлении: доля нулевая, но получатель
        // из списка не исчезает, и остаток не оседает на нём.
        $total = Money::ofMinor(101, 'RUB');

        $parts = $total->allocate([1, 0, 1], AllocationRule::RemainderToFirst);

        self::assertSame(0, self::minorAmounts($parts)[1]);
        self::assertSame($total->minorAmount(), array_sum(self::minorAmounts($parts)));
    }

    public function testZeroAmountAllocatesToZeroes(): void
    {
        $parts = Money::ofMinor(0, 'RUB')->allocate([1, 1], AllocationRule::RemainderToFirst);

        self::assertSame([0, 0], self::minorAmounts($parts));
    }

    public function testCurrencyIsCarriedAndNotDefaulted(): void
    {
        // Умолчания у валюты нет (ADR-004): что передали, то и в частях.
        $parts = Money::ofMinor(300, 'USD')->allocate([1, 1], AllocationRule::RemainderToFirst);

        self::assertSame('USD', $parts[0]->currency());
        self::assertSame('USD', $parts[1]->currency());
    }

    /**
     * @param list<Money> $parts
     *
     * @return list<int>
     */
    private static function minorAmounts(array $parts): array
    {
        return array_map(static fn (Money $part): int => $part->minorAmount(), $parts);
    }

    public function testAddsMoneyOfTheSameCurrency(): void
    {
        $total = Money::ofMinor(274_700, 'RUB')->plus(Money::ofMinor(-126_362, 'RUB'));

        // Расходы приходят от площадки отрицательными, и сложение —
        // единственная операция, которая нужна: «вычесть расход» означало
        // бы гадать, каким знаком он пришёл.
        self::assertSame(148_338, $total->minorAmount());
    }

    public function testRefusesToAddDifferentCurrencies(): void
    {
        // Молчаливое приведение по курсу запрещено (ADR-004), и сложить
        // разные валюты — то же самое, только без курса.
        $this->expectException(\InvalidArgumentException::class);

        Money::ofMinor(100, 'RUB')->plus(Money::ofMinor(100, 'USD'));
    }

    public function testSumsAListOfTheSameCurrency(): void
    {
        $total = Money::sum([
            Money::ofMinor(-6_900, 'RUB'),
            Money::ofMinor(-785, 'RUB'),
            Money::ofMinor(-1_943, 'RUB'),
        ]);

        self::assertSame(-9_628, $total->minorAmount());
        self::assertSame('RUB', $total->currency());
    }

    public function testRefusesToSumAnEmptyList(): void
    {
        // Валюта результата была бы догадкой, а умолчаний у валюты
        // не бывает (ADR-004).
        $this->expectException(\InvalidArgumentException::class);

        Money::sum([]);
    }
}
