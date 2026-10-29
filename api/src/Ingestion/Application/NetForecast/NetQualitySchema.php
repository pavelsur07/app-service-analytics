<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use Symfony\Component\Uid\Uuid;

/** Validation at the private JSON boundary; no coercion or ambient scope. */
final class NetQualitySchema
{
    /** @param array<array-key, mixed> $data */
    public static function string(array $data, string $key, int $max = 256): string
    {
        $value = $data[$key] ?? null;
        if (!\is_string($value) || '' === $value || \strlen($value) > $max) {
            throw new \InvalidArgumentException('Invalid quality field: '.$key);
        }

        return $value;
    }

    public static function uuid(string $value, bool $v7 = false): string
    {
        if (!Uuid::isValid($value) || Uuid::fromString($value)->toRfc4122() !== $value || ($v7 && '7' !== $value[14])) {
            throw new \InvalidArgumentException('Quality identity must be a canonical UUID.');
        }

        return $value;
    }

    public static function utc(string $value): \DateTimeImmutable
    {
        $at = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $value, new \DateTimeZone('UTC'));
        if (false === $at || $at->format('Y-m-d\TH:i:s.u\Z') !== $value) {
            throw new \InvalidArgumentException('Quality timestamp requires exact UTC microseconds.');
        }

        return $at;
    }

    public static function date(string $value): string
    {
        $at = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('Europe/Moscow'));
        if (false === $at || $at->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Invalid quality calendar date.');
        }

        return $value;
    }

    /** @return list<string> */
    public static function strings(mixed $value, int $max): array
    {
        if (!\is_array($value) || !array_is_list($value) || [] === $value || \count($value) > $max) {
            throw new \InvalidArgumentException('Invalid bounded quality string list.');
        }
        $result = [];
        foreach ($value as $item) {
            if (!\is_string($item) || '' === $item || \strlen($item) > 256) {
                throw new \InvalidArgumentException('Invalid quality list member.');
            }
            $result[] = $item;
        }
        if (\count(array_unique($result, \SORT_STRING)) !== \count($result)) {
            throw new \InvalidArgumentException('Duplicate quality list member.');
        }
        sort($result, \SORT_STRING);

        return $result;
    }

    /** @return list<array<string, mixed>> */
    public static function rows(mixed $value, int $max): array
    {
        if (!\is_array($value) || !array_is_list($value) || \count($value) > $max) {
            throw new \InvalidArgumentException('Invalid bounded quality rows.');
        }
        $rows = [];
        foreach ($value as $row) {
            if (!\is_array($row)) {
                throw new \InvalidArgumentException('Invalid quality row.');
            }
            $object = [];
            foreach ($row as $key => $item) {
                if (!\is_string($key)) {
                    throw new \InvalidArgumentException('Quality object requires named fields.');
                }
                $object[$key] = $item;
            }
            $rows[] = $object;
        }

        return $rows;
    }

    /** @param array<array-key, mixed> $data
     * @param list<string> $expected */
    public static function keys(array $data, array $expected): void
    {
        $keys = array_keys($data);
        sort($keys);
        sort($expected);
        if ($keys !== $expected) {
            throw new \InvalidArgumentException('Unexpected quality schema fields.');
        }
    }
}
