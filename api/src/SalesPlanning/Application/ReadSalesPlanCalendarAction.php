<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

use App\SalesPlanning\Infrastructure\Query\SalesPlanCalendarEntryRow;
use App\SalesPlanning\Infrastructure\Query\SalesPlanCalendarPage;
use App\SalesPlanning\Infrastructure\Query\SalesPlanCalendarQuery;
use Symfony\Component\Uid\Uuid;

final readonly class ReadSalesPlanCalendarAction
{
    public function __construct(private SalesPlanCalendarQuery $query)
    {
    }

    public function __invoke(string $companyId, string $planId, int $limit = 50, ?string $cursor = null): ?SalesPlanCalendarPage
    {
        if ($limit < 1 || $limit > 200) {
            throw new \InvalidArgumentException('Лимит календаря от 1 до 200.');
        }
        $after = null === $cursor ? null : self::decode($cursor);
        /** @var list<array{version: int, status: string, date_from: string, date_to: string, entry_id: ?string, marketplace_sku: ?string, kind: ?string, entry_from: ?string, entry_to: ?string}> $rows */
        $rows = $this->query->build($companyId, $planId, $limit, $after['date'] ?? null, $after['id'] ?? null)->executeQuery()->fetchAllAssociative();
        if ([] === $rows) {
            return null;
        }
        $header = $rows[0];
        $scope = hash('sha256', json_encode([$companyId, $planId, $header['version'], $limit], \JSON_THROW_ON_ERROR));
        if (null !== $after && !hash_equals($scope, $after['scope'])) {
            throw new \InvalidArgumentException('Курсор относится к другой версии или странице календаря.');
        }
        $entries = [];
        foreach ($rows as $row) {
            if (null !== $row['entry_id']) {
                \assert(null !== $row['kind'] && null !== $row['entry_from'] && null !== $row['entry_to']);
                $entries[] = new SalesPlanCalendarEntryRow($row['entry_id'], $row['marketplace_sku'], $row['kind'], $row['entry_from'], $row['entry_to']);
            }
        }
        if (null !== $after && [] === $entries) {
            throw new \InvalidArgumentException('Страница календаря за пределами набора.');
        }
        $hasMore = \count($entries) > $limit;
        $entries = \array_slice($entries, 0, $limit);
        $next = null;
        if ($hasMore) {
            \assert([] !== $entries);
            $last = $entries[array_key_last($entries)];
            $next = base64_encode(json_encode(['scope' => $scope, 'date' => $last->dateFrom, 'id' => $last->id], \JSON_THROW_ON_ERROR));
        }

        return new SalesPlanCalendarPage($planId, $header['version'], $header['status'], $header['date_from'], $header['date_to'], $entries, $next);
    }

    /** @return array{scope: string, date: string, id: string} */
    private static function decode(string $cursor): array
    {
        if ('' === $cursor || \strlen($cursor) > 1024 || false === ($raw = base64_decode($cursor, true))) {
            throw new \InvalidArgumentException('Некорректный курсор календаря.');
        }
        try {
            $data = json_decode($raw, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Некорректный курсор календаря.');
        }
        if (!\is_array($data) || 3 !== \count($data) || !\is_string($data['scope'] ?? null) || !\is_string($data['date'] ?? null) || !\is_string($data['id'] ?? null)
            || 1 !== preg_match('/^[a-f0-9]{64}$/D', $data['scope']) || !Uuid::isValid($data['id'])
            || 1 !== preg_match('/^(?!0000)\d{4}-\d{2}-\d{2}$/D', $data['date'])) {
            throw new \InvalidArgumentException('Некорректный курсор календаря.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $data['date'], new \DateTimeZone('UTC'));
        if (false === $date || $date->format('Y-m-d') !== $data['date']) {
            throw new \InvalidArgumentException('Некорректная дата курсора.');
        }

        return ['scope' => $data['scope'], 'date' => $data['date'], 'id' => $data['id']];
    }
}
