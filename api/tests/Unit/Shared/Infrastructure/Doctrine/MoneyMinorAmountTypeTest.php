<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\Type\MoneyMinorAmountType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use PHPUnit\Framework\TestCase;

/**
 * Обязательное покрытие §9 в части денег: путь минорных единиц из базы
 * в PHP. PostgreSQL отдаёт bigint строкой, и именно здесь она становится
 * целым числом — молчаливая ошибка тут означала бы деньги, испорченные
 * при чтении, а не при расчёте.
 */
final class MoneyMinorAmountTypeTest extends TestCase
{
    public function testNumericStringFromDatabaseBecomesInt(): void
    {
        // Так значение приходит из PostgreSQL: bigint это строка.
        self::assertSame(216_000, $this->convert('216000'));
    }

    public function testNegativeAmountSurvives(): void
    {
        // Комиссии площадки отрицательные (фикстура Ozon: -32 400).
        self::assertSame(-32_400, $this->convert('-32400'));
    }

    public function testAmountsBeyondIntegerColumnRangeSurvive(): void
    {
        // Колонка bigint выбрана не случайно: 10 миллиардов копеек это
        // сто миллионов рублей, и int32 их не вмещает. Проверяем, что
        // за пределами int32 значение не портится.
        self::assertSame(10_000_000_000, $this->convert('10000000000'));
    }

    public function testZeroIsAValueAndNotAnAbsence(): void
    {
        self::assertSame(0, $this->convert('0'));
    }

    public function testNullStaysNull(): void
    {
        // Нулевая сумма и отсутствие суммы — разные вещи.
        self::assertNull($this->convert(null));
    }

    public function testNonNumericValueFailsLoudly(): void
    {
        // Тихое приведение мусора к нулю превратило бы испорченную строку
        // в «продаж на 0 рублей» — отказ, который выглядит как данные.
        $this->expectException(\UnexpectedValueException::class);
        $this->convert([]);
    }

    private function convert(mixed $value): ?int
    {
        $platform = self::createStub(AbstractPlatform::class);

        return (new MoneyMinorAmountType())->convertToPHPValue($value, $platform);
    }
}
