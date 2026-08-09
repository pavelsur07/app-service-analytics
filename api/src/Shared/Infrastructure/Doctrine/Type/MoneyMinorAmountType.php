<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * Минорные единицы Money как bigint (ADR-004). Валюта — отдельная колонка
 * char(3) на строку факта, эта колонка её не знает: собрать Money обратно
 * (сумма + валюта) — дело сущности, не типа. Doctrine Type здесь —
 * не полноценная (де)сериализация Value Object, а явное имя колонки
 * в маппинге вместо голого bigint (ADR-004: «каждое денежное поле
 * требует явного Doctrine Type»).
 */
final class MoneyMinorAmountType extends Type
{
    public const string NAME = 'money_minor_amount';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getBigIntTypeDeclarationSQL($column);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?int
    {
        if (null === $value) {
            return null;
        }
        if (!\is_int($value) && !\is_string($value)) {
            throw new \UnexpectedValueException('money_minor_amount column value must be an int or a numeric string.');
        }

        return (int) $value;
    }
}
