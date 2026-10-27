<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Request;

use App\SalesPlanning\Application\SalesPlanCalendarInput;
use App\SalesPlanning\Domain\PlannedCalendarEvent;
use App\SalesPlanning\Domain\SalesPlanCalendarEntry;
use App\SalesPlanning\Domain\SalesPlanCalendarKind;
use Symfony\Component\Uid\Uuid;

final class SalesPlanCalendarRequest
{
    /** @return array{version: int, entries: list<SalesPlanCalendarInput>} */
    public static function fromJson(string $body): array
    {
        try {
            $decoded = json_decode($body, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Некорректный JSON.');
        }
        if (!$decoded instanceof \stdClass || [] !== array_diff(array_keys(get_object_vars($decoded)), ['version', 'entries'])) {
            throw new \InvalidArgumentException('Нужны только version и entries.');
        }
        $version = $decoded->version ?? null;
        $rawEntries = $decoded->entries ?? null;
        if (!\is_int($version) || $version < 1 || $version > 2147483647 || !\is_array($rawEntries) || \count($rawEntries) > SalesPlanCalendarEntry::MaxEntries) {
            throw new \InvalidArgumentException('Нужны положительная целая version и массив не более 500 entries.');
        }
        $entries = [];
        foreach ($rawEntries as $raw) {
            if (!$raw instanceof \stdClass || [] !== array_diff(array_keys(get_object_vars($raw)), ['id', 'kind', 'marketplaceSku', 'dateFrom', 'dateTo'])) {
                throw new \InvalidArgumentException('Некорректная календарная запись.');
            }
            $id = $raw->id ?? null;
            $kind = $raw->kind ?? null;
            $sku = $raw->marketplaceSku ?? null;
            if ((null !== $id && (!\is_string($id) || !Uuid::isValid($id)))
                || !\is_string($kind) || null === ($kind = SalesPlanCalendarKind::tryFrom($kind))
                || !property_exists($raw, 'marketplaceSku') || (null !== $sku && !\is_string($sku))) {
                throw new \InvalidArgumentException('Неверные id, kind или marketplaceSku.');
            }
            $entries[] = new SalesPlanCalendarInput(null === $id ? null : Uuid::fromString($id), new PlannedCalendarEvent($kind, $sku, self::date($raw->dateFrom ?? null), self::date($raw->dateTo ?? null)));
        }

        return ['version' => $version, 'entries' => $entries];
    }

    public static function date(mixed $raw): \DateTimeImmutable
    {
        $date = \is_string($raw) && 1 === preg_match('/^(?!0000)\d{4}-\d{2}-\d{2}$/D', $raw)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, new \DateTimeZone('UTC')) : false;
        if (false === $date || $date->format('Y-m-d') !== $raw) {
            throw new \InvalidArgumentException('Нужна действительная дата YYYY-MM-DD.');
        }

        return $date;
    }
}
