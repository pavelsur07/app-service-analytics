<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\NetForecast;

final class NetTrainingValue
{
    /** @param array<string, mixed> $row */
    public static function budgetFailure(array $row): ?NetTrainingBudgetFailure
    {
        $input = self::nullableString($row['budget_input']);

        return null === $input ? null : new NetTrainingBudgetFailure($input, self::integer($row['budget_observed']), self::integer($row['budget_limit']));
    }

    public static function integer(mixed $value): int
    {
        if (\is_int($value) && $value >= 0) {
            return $value;
        }
        if (\is_string($value) && 1 === preg_match('/^(0|[1-9][0-9]*)$/D', $value)) {
            $integer = filter_var($value, \FILTER_VALIDATE_INT);
            if (false !== $integer) {
                return $integer;
            }
        }

        throw new \UnexpectedValueException('Expected an exact non-negative training integer.');
    }

    public static function nullableInteger(mixed $value): ?int
    {
        return null === $value ? null : self::integer($value);
    }

    public static function nullableString(mixed $value): ?string
    {
        if (null === $value || \is_string($value)) {
            return $value;
        }

        throw new \UnexpectedValueException('Expected a nullable training string.');
    }

    public static function boolean(mixed $value): bool
    {
        if (!\is_bool($value)) {
            throw new \UnexpectedValueException('Expected a training boolean.');
        }

        return $value;
    }
}
