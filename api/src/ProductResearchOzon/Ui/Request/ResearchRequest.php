<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Ui\Request;

use App\ProductResearchOzon\Application\AddPeriodsInput;
use App\ProductResearchOzon\Application\CopyProjectInput;
use App\ProductResearchOzon\Application\CreateProjectInput;
use App\ProductResearchOzon\Domain\CalendarSelection;
use App\ProductResearchOzon\Domain\PeriodRange;

/** Dynamic JSON ends here; Application receives validated DTOs only. */
final class ResearchRequest
{
    /** @return array<string, mixed> */
    public static function object(string $json): array
    {
        if (\strlen($json) > 32768) {
            throw new \InvalidArgumentException('invalid_request');
        }
        try {
            $object = json_decode($json, false, 20, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('invalid_request');
        }
        if (!$object instanceof \stdClass) {
            throw new \InvalidArgumentException('invalid_request');
        }

        return get_object_vars($object);
    }

    /** @param array<string, mixed> $input
     * @param list<string> $keys
     */
    public static function keys(array $input, array $keys): void
    {
        if ([] !== array_diff(array_keys($input), $keys)) {
            throw new \InvalidArgumentException('unknown_field');
        }
    }

    public static function create(string $json): CreateProjectInput
    {
        $input = self::object($json);
        self::keys($input, ['categoryPath', 'periods', 'shortcut', 'includeCurrentMonth', 'previewCalendarDate', 'name', 'comment']);

        return new CreateProjectInput(self::text($input, 'categoryPath', 2048), self::selection($input), self::flag($input) ?? false, self::date($input), self::optionalText($input, 'name', 200), self::optionalText($input, 'comment', 2000) ?? '');
    }

    public static function add(string $json): AddPeriodsInput
    {
        $input = self::object($json);
        self::keys($input, ['periods', 'expectedVersion', 'previewCalendarDate', 'includeCurrentMonth']);

        return new AddPeriodsInput(self::periods($input['periods'] ?? null), self::version($input), self::date($input), self::flag($input));
    }

    public static function copy(string $json): CopyProjectInput
    {
        $input = self::object($json);
        self::keys($input, ['expectedVersion', 'previewCalendarDate', 'overrides']);
        $overrides = \array_key_exists('overrides', $input) ? $input['overrides'] : new \stdClass();
        if (!$overrides instanceof \stdClass) {
            throw new \InvalidArgumentException('invalid_request');
        }
        $values = get_object_vars($overrides);
        self::keys($values, ['categoryPath', 'name', 'comment', 'periods', 'shortcut', 'includeCurrentMonth']);

        return new CopyProjectInput(self::version($input), self::date($input), \array_key_exists('categoryPath', $values) ? self::text($values, 'categoryPath', 2048) : null, self::optionalText($values, 'name', 200), self::optionalText($values, 'comment', 2000), \array_key_exists('periods', $values) || \array_key_exists('shortcut', $values) ? self::selection($values) : null, self::flag($values));
    }

    /** @param array<string, mixed> $input */
    public static function selection(array $input): CalendarSelection
    {
        if (\array_key_exists('periods', $input) === \array_key_exists('shortcut', $input)) {
            throw new \InvalidArgumentException('invalid_selection');
        }
        if (\array_key_exists('periods', $input)) {
            return new CalendarSelection(self::periods($input['periods']));
        }
        $shortcut = $input['shortcut'];
        if (!$shortcut instanceof \stdClass) {
            throw new \InvalidArgumentException('invalid_shortcut');
        }
        $values = get_object_vars($shortcut);
        $kind = $values['kind'] ?? null;
        if ('last_closed_months' === $kind) {
            self::keys($values, ['kind', 'months']);
            $count = self::integer($values['months'] ?? null);
            if (!\is_int($count) || !\in_array($count, [3, 6, 12], true)) {
                throw new \InvalidArgumentException('invalid_shortcut');
            }

            return new CalendarSelection(recentMonths: $count);
        }
        if ('previous_year' === $kind) {
            self::keys($values, ['kind', 'period']);

            return new CalendarSelection(previousYear: self::period($values['period'] ?? null));
        }
        throw new \InvalidArgumentException('invalid_shortcut');
    }

    /** @return list<PeriodRange> */
    private static function periods(mixed $input): array
    {
        if (!\is_array($input) || !array_is_list($input) || [] === $input || \count($input) > 24) {
            throw new \InvalidArgumentException('period_limit');
        }

        return array_map(self::period(...), $input);
    }

    private static function period(mixed $input): PeriodRange
    {
        if (!$input instanceof \stdClass) {
            throw new \InvalidArgumentException('invalid_period');
        }
        $values = get_object_vars($input);
        self::keys($values, ['fromMonth', 'toMonth', 'name']);

        return new PeriodRange(self::text($values, 'fromMonth', 7), self::text($values, 'toMonth', 7), self::optionalText($values, 'name', 100) ?? '');
    }

    /** @param array<string, mixed> $input */
    public static function flag(array $input): ?bool
    {
        if (!\array_key_exists('includeCurrentMonth', $input)) {
            return null;
        }
        if (!\is_bool($input['includeCurrentMonth'])) {
            throw new \InvalidArgumentException('invalid_request');
        }

        return $input['includeCurrentMonth'];
    }

    /** @param array<string, mixed> $input */
    public static function optionalText(array $input, string $key, int $max): ?string
    {
        return \array_key_exists($key, $input) ? self::text($input, $key, $max, true) : null;
    }

    /** @param array<string, mixed> $input */
    private static function text(array $input, string $key, int $max, bool $allowEmpty = false): string
    {
        $value = $input[$key] ?? null;
        if (!\is_string($value) || str_contains($value, "\0") || mb_strlen($value) > $max || (!$allowEmpty && '' === trim($value))) {
            throw new \InvalidArgumentException('invalid_request');
        }

        return $value;
    }

    /** @param array<string, mixed> $input */
    private static function version(array $input): int
    {
        $version = self::integer($input['expectedVersion'] ?? null);
        if (!\is_int($version) || $version < 1 || $version > 2147483647) {
            throw new \InvalidArgumentException('invalid_version');
        }

        return $version;
    }

    private static function integer(mixed $value): ?int
    {
        // JSON Schema integer admits 1.0; strings/bools/fractions remain invalid.
        if (\is_float($value) && is_finite($value) && floor($value) === $value && $value >= 0 && $value <= 2147483647) {
            return (int) $value;
        }

        return \is_int($value) ? $value : null;
    }

    /** @param array<string, mixed> $input */
    private static function date(array $input): string
    {
        $date = self::text($input, 'previewCalendarDate', 10);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (false === $parsed || $parsed->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('invalid_request');
        }

        return $date;
    }
}
