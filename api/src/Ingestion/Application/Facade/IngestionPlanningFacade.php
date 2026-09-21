<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Facade;

use App\Ingestion\Infrastructure\Query\PlanningCohortProvenanceQuery;
use App\Ingestion\Infrastructure\Query\PlanningMarketplaceSkusQuery;
use App\Ingestion\Infrastructure\Query\PlanningOrderCohortsQuery;
use App\Ingestion\Infrastructure\Query\PlanningOutcomeQueryGuard;
use App\Ingestion\Infrastructure\Query\PlanningResolutionsQuery;
use App\Ingestion\Infrastructure\Query\PlanningSourceStateQuery;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class IngestionPlanningFacade
{
    private const int MAX_KNOWN_MARKETPLACE_SKUS = 10_000;

    public function __construct(
        private PlanningOrderCohortsQuery $cohorts,
        private PlanningCohortProvenanceQuery $provenance,
        private PlanningSourceStateQuery $sourceState,
        private PlanningResolutionsQuery $resolutions,
        private PlanningMarketplaceSkusQuery $skus,
        private PlanningOutcomeQueryGuard $queryGuard,
        #[Autowire('%kernel.secret%')]
        private string $cursorSecret,
    ) {
    }

    /** @param list<string> $marketplaceSkus */
    public function planningOrderCohorts(
        string $companyId,
        string $marketplaceAccountId,
        array $marketplaceSkus,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $limit = 50,
        ?string $cursor = null,
    ): PlanningOrderCohortPage {
        return $this->queryGuard->read(fn (): PlanningOrderCohortPage => $this->planningOrderCohortsRead(
            $companyId, $marketplaceAccountId, $marketplaceSkus, $from, $to, $limit, $cursor,
        ));
    }

    /** @param list<string> $marketplaceSkus */
    private function planningOrderCohortsRead(
        string $companyId,
        string $marketplaceAccountId,
        array $marketplaceSkus,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $limit,
        ?string $cursor,
    ): PlanningOrderCohortPage {
        $from = $from->setTimezone(new \DateTimeZone('Europe/Moscow'));
        $to = $to->setTimezone(new \DateTimeZone('Europe/Moscow'));
        $this->validateWindow($marketplaceSkus, $from, $to);
        $this->validateLimit($limit);

        if ([] === $marketplaceSkus) {
            return new PlanningOrderCohortPage([], null, false, null, $this->sourceState->version($companyId, $marketplaceAccountId));
        }

        $sourceVersion = $this->sourceState->version($companyId, $marketplaceAccountId);
        $scope = $this->scope('cohort', [$companyId, $marketplaceAccountId, $marketplaceSkus, $from->format('Y-m-d'), $to->format('Y-m-d'), $sourceVersion]);
        $position = null === $cursor ? null : $this->decodeCursor($cursor, $scope);

        /** @var list<array{marketplace_sku: string, business_date: string, ordered: int|string, bought: int|string, terminal_no_buy: int|string, open_eligible: int|string, unknown: int|string}> $raw */
        $raw = $this->cohorts->build(
            $companyId, $marketplaceAccountId, $marketplaceSkus, $from, $to, $limit,
            $position['sku'] ?? null, $position['date'] ?? null,
        )->executeQuery()->fetchAllAssociative();

        $hasNext = \count($raw) > $limit;
        $raw = \array_slice($raw, 0, $limit);
        $keys = array_map(static fn (array $row): array => [
            'sku' => (string) $row['marketplace_sku'], 'date' => (string) $row['business_date'],
        ], $raw);
        $rawLinks = [];
        if ([] !== $keys) {
            $provenance = $this->provenance->build($companyId, $marketplaceAccountId, $keys)->executeQuery()->fetchAllAssociative();
            foreach ($provenance as $row) {
                $sku = $row['sku'] ?? null;
                $date = $row['business_date'] ?? null;
                $rawIds = $row['raw_document_ids'] ?? null;
                if (!\is_string($sku) || !\is_string($date) || !\is_string($rawIds)) {
                    throw new \UnexpectedValueException('Planning cohort provenance query returned invalid columns.');
                }
                $rawLinks[json_encode([$sku, $date], \JSON_THROW_ON_ERROR)] = [
                    'ids' => self::decodeRawIds($rawIds),
                    'truncated' => (bool) ($row['raw_document_ids_truncated'] ?? false),
                ];
            }
        }

        $items = array_map(
            static fn (array $row): PlanningOrderCohort => new PlanningOrderCohort(
                marketplaceSku: (string) $row['marketplace_sku'],
                orderBusinessDate: (string) $row['business_date'],
                ordered: (int) $row['ordered'],
                bought: (int) $row['bought'],
                terminalNoBuy: (int) $row['terminal_no_buy'],
                openEligible: (int) $row['open_eligible'],
                unknown: (int) $row['unknown'],
                rawDocumentIds: $rawLinks[json_encode([(string) $row['marketplace_sku'], (string) $row['business_date']], \JSON_THROW_ON_ERROR)]['ids'] ?? [],
                rawDocumentIdsTruncated: $rawLinks[json_encode([(string) $row['marketplace_sku'], (string) $row['business_date']], \JSON_THROW_ON_ERROR)]['truncated'] ?? false,
            ),
            $raw,
        );

        $last = [] === $items ? null : $items[\count($items) - 1];
        $nextCursor = $hasNext && null !== $last
            ? $this->encodeCursor($scope, $last->marketplaceSku, $last->orderBusinessDate)
            : null;

        $coverage = $this->sourceState->orderCoverage($companyId, $marketplaceAccountId, $from, $to);
        $this->assertStableVersion($companyId, $marketplaceAccountId, $sourceVersion);

        return new PlanningOrderCohortPage($items, $nextCursor, $coverage['complete'], $coverage['lastCompleteAt'], $sourceVersion);
    }

    /** @param list<string> $marketplaceSkus */
    public function planningResolutionTotals(
        string $companyId,
        string $marketplaceAccountId,
        array $marketplaceSkus,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        PlanningObservationDateAxis $dateAxis,
    ): PlanningResolutionTotals {
        return $this->queryGuard->read(fn (): PlanningResolutionTotals => $this->planningResolutionTotalsRead(
            $companyId, $marketplaceAccountId, $marketplaceSkus, $from, $to, $dateAxis,
        ));
    }

    /** @param list<string> $marketplaceSkus */
    private function planningResolutionTotalsRead(
        string $companyId,
        string $marketplaceAccountId,
        array $marketplaceSkus,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        PlanningObservationDateAxis $dateAxis,
    ): PlanningResolutionTotals {
        $from = $from->setTimezone(new \DateTimeZone('Europe/Moscow'));
        $to = $to->setTimezone(new \DateTimeZone('Europe/Moscow'));
        $this->validateWindow($marketplaceSkus, $from, $to);
        $version = $this->sourceState->version($companyId, $marketplaceAccountId);
        if ([] === $marketplaceSkus) {
            return new PlanningResolutionTotals([], false, null, $version);
        }
        /** @var list<array{marketplace_sku: string, delivered: int|string, returned: int|string, pre_handover_no_buy: int|string, post_handover_no_buy: int|string, other_terminal_no_buy: int|string}> $rows */
        $rows = $this->resolutions->totals($companyId, $marketplaceAccountId, $marketplaceSkus, $from, $to, $dateAxis->value)->executeQuery()->fetchAllAssociative();
        $items = array_map(static fn (array $row): PlanningResolutionTotalRow => new PlanningResolutionTotalRow(
            (string) $row['marketplace_sku'], (int) $row['delivered'], (int) $row['returned'],
            (int) $row['pre_handover_no_buy'], (int) $row['post_handover_no_buy'], (int) $row['other_terminal_no_buy'],
        ), $rows);
        $coverage = $this->sourceState->observationCoverage($companyId, $marketplaceAccountId, $from, $to);
        $complete = $coverage['complete'] && !$this->resolutions->hasUndatedCurrentOutcome($companyId, $marketplaceAccountId, $marketplaceSkus, $from, $to, $dateAxis->value);
        $this->assertStableVersion($companyId, $marketplaceAccountId, $version);

        return new PlanningResolutionTotals($items, $complete, $coverage['lastCompleteAt'], $version);
    }

    /** @param list<string> $marketplaceSkus */
    public function planningResolutionObservations(
        string $companyId,
        string $marketplaceAccountId,
        array $marketplaceSkus,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        PlanningObservationDateAxis $dateAxis,
        int $limit = 50,
        ?string $cursor = null,
    ): PlanningResolutionObservationPage {
        return $this->queryGuard->read(fn (): PlanningResolutionObservationPage => $this->planningResolutionObservationsRead(
            $companyId, $marketplaceAccountId, $marketplaceSkus, $from, $to, $dateAxis, $limit, $cursor,
        ));
    }

    /** @param list<string> $marketplaceSkus */
    private function planningResolutionObservationsRead(
        string $companyId,
        string $marketplaceAccountId,
        array $marketplaceSkus,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        PlanningObservationDateAxis $dateAxis,
        int $limit,
        ?string $cursor,
    ): PlanningResolutionObservationPage {
        $from = $from->setTimezone(new \DateTimeZone('Europe/Moscow'));
        $to = $to->setTimezone(new \DateTimeZone('Europe/Moscow'));
        $this->validateWindow($marketplaceSkus, $from, $to);
        $this->validateLimit($limit);
        $version = $this->sourceState->version($companyId, $marketplaceAccountId);
        if ([] === $marketplaceSkus) {
            return new PlanningResolutionObservationPage([], null, false, null, $version);
        }
        $scope = $this->scope('observations', [$companyId, $marketplaceAccountId, $marketplaceSkus, $from->format('Y-m-d'), $to->format('Y-m-d'), $dateAxis->value, $version]);
        $position = null === $cursor ? null : $this->decodePosition($cursor, $scope, ['sku', 'row', 'key']);
        /** @var list<array{marketplace_sku: string, source_row_id: string, allocation_key: string, outcome: string, quantity: int|string, first_known_outcome_at: ?string, first_regularly_observed_at: ?string, source_event_at: ?string, backfill: bool, raw_document_id: ?string}> $rows */
        $rows = $this->resolutions->observations(
            $companyId, $marketplaceAccountId, $marketplaceSkus, $from, $to, $dateAxis->value, $limit + 1,
            $position['sku'] ?? null, $position['row'] ?? null, $position['key'] ?? null,
        )->executeQuery()->fetchAllAssociative();
        $hasNext = \count($rows) > $limit;
        $rows = \array_slice($rows, 0, $limit);
        $items = array_map(static fn (array $row): PlanningResolutionObservation => new PlanningResolutionObservation(
            (string) $row['marketplace_sku'], (string) $row['source_row_id'], (string) $row['allocation_key'], (string) $row['outcome'],
            (int) $row['quantity'], $row['first_known_outcome_at'], $row['first_regularly_observed_at'],
            $row['source_event_at'], (bool) $row['backfill'], $row['raw_document_id'],
        ), $rows);
        $last = [] === $items ? null : $items[\count($items) - 1];
        $nextCursor = $hasNext && null !== $last ? $this->encodePosition($scope, [
            'sku' => $last->marketplaceSku, 'row' => $last->sourceRowId, 'key' => $last->allocationKey,
        ]) : null;
        $coverage = $this->sourceState->observationCoverage($companyId, $marketplaceAccountId, $from, $to);
        $complete = $coverage['complete'] && !$this->resolutions->hasUndatedCurrentOutcome($companyId, $marketplaceAccountId, $marketplaceSkus, $from, $to, $dateAxis->value);
        $this->assertStableVersion($companyId, $marketplaceAccountId, $version);

        return new PlanningResolutionObservationPage($items, $nextCursor, $complete, $coverage['lastCompleteAt'], $version);
    }

    /**
     * @param list<string> $marketplaceSkus
     *
     * @return list<string>
     */
    public function knownMarketplaceSkus(string $companyId, string $marketplaceAccountId, array $marketplaceSkus): array
    {
        if (\count($marketplaceSkus) > self::MAX_KNOWN_MARKETPLACE_SKUS) {
            throw new \InvalidArgumentException('Too many marketplace SKUs.');
        }

        if ([] === $marketplaceSkus) {
            return [];
        }
        $rows = $this->skus->known($companyId, $marketplaceAccountId, $marketplaceSkus)->executeQuery()->fetchFirstColumn();
        foreach ($rows as $row) {
            if (!\is_string($row)) {
                throw new \UnexpectedValueException('Marketplace SKU query returned a non-string value.');
            }
        }

        return $rows;
    }

    /**
     * @param list<string> $marketplaceSkus
     *
     * @return list<MarketplaceSku>
     */
    public function knownMarketplaceSkuDetails(string $companyId, string $marketplaceAccountId, array $marketplaceSkus): array
    {
        if (\count($marketplaceSkus) > self::MAX_KNOWN_MARKETPLACE_SKUS) {
            throw new \InvalidArgumentException('Too many marketplace SKUs.');
        }
        if ([] === $marketplaceSkus) {
            return [];
        }
        /** @var list<array{marketplace_sku: string, offer_id: ?string, name: ?string}> $rows */
        $rows = $this->skus->knownDetails($companyId, $marketplaceAccountId, $marketplaceSkus)->executeQuery()->fetchAllAssociative();

        return array_map(static fn (array $row): MarketplaceSku => new MarketplaceSku($row['marketplace_sku'], $row['offer_id'], $row['name']), $rows);
    }

    public function searchMarketplaceSkus(string $companyId, string $marketplaceAccountId, string $search, int $limit = 50, ?string $cursor = null): MarketplaceSkuPage
    {
        return $this->queryGuard->read(fn (): MarketplaceSkuPage => $this->searchMarketplaceSkusRead($companyId, $marketplaceAccountId, $search, $limit, $cursor));
    }

    private function searchMarketplaceSkusRead(string $companyId, string $marketplaceAccountId, string $search, int $limit, ?string $cursor): MarketplaceSkuPage
    {
        $this->validateLimit($limit);
        $version = $this->sourceState->version($companyId, $marketplaceAccountId);
        $scope = $this->scope('sku-search', [$companyId, $marketplaceAccountId, $search, $version]);
        $position = null === $cursor ? null : $this->decodePosition($cursor, $scope, ['sku']);
        /** @var list<array{marketplace_sku: string, offer_id: ?string, name: ?string}> $rows */
        $rows = $this->skus->search($companyId, $marketplaceAccountId, $search, $limit + 1, $position['sku'] ?? null)->executeQuery()->fetchAllAssociative();
        $hasNext = \count($rows) > $limit;
        $rows = \array_slice($rows, 0, $limit);
        $items = array_map(static fn (array $row): MarketplaceSku => new MarketplaceSku(
            (string) $row['marketplace_sku'], $row['offer_id'], $row['name'],
        ), $rows);
        $last = [] === $items ? null : $items[\count($items) - 1];
        $this->assertStableVersion($companyId, $marketplaceAccountId, $version);

        return new MarketplaceSkuPage($items, $hasNext && null !== $last ? $this->encodePosition($scope, ['sku' => $last->marketplaceSku]) : null, $version);
    }

    /** @param list<string> $skus */
    private function validateWindow(array $skus, \DateTimeImmutable $from, \DateTimeImmutable $to): void
    {
        $first = new \DateTimeImmutable($from->format('Y-m-d'));
        $last = new \DateTimeImmutable($to->format('Y-m-d'));
        if (\count($skus) > 200 || $last < $first || (int) $first->diff($last)->format('%a') > 365) {
            throw new \InvalidArgumentException('Planning SKU set or date range is invalid.');
        }
    }

    private function validateLimit(int $limit): void
    {
        if ($limit < 1 || $limit > 200) {
            throw new \InvalidArgumentException('Planning page limit must be between 1 and 200.');
        }
    }

    /** @return list<string> */
    private static function decodeRawIds(string $json): array
    {
        $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new \UnexpectedValueException('Planning cohort provenance query returned invalid raw IDs.');
        }
        $ids = [];
        foreach ($decoded as $id) {
            if (!\is_string($id)) {
                throw new \UnexpectedValueException('Planning cohort provenance query returned invalid raw IDs.');
            }
            $ids[] = $id;
        }

        return $ids;
    }

    /** @param list<mixed> $parts */
    private function scope(string $kind, array $parts): string
    {
        return hash('sha256', json_encode([$kind, ...$parts], \JSON_THROW_ON_ERROR));
    }

    private function assertStableVersion(string $companyId, string $accountId, string $version): void
    {
        if ($version !== $this->sourceState->version($companyId, $accountId)) {
            throw new \RuntimeException('Planning source changed during the read; retry the full calculation.');
        }
    }

    /** @param array<string, string> $position */
    private function encodePosition(string $scope, array $position): string
    {
        $payload = base64_encode(json_encode(['scope' => $scope, 'position' => $position], \JSON_THROW_ON_ERROR));

        return $payload.'.'.hash_hmac('sha256', $payload, $this->cursorSecret);
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, string>
     */
    private function decodePosition(string $cursor, string $scope, array $keys): array
    {
        $parts = explode('.', $cursor, 2);
        if (2 !== \count($parts) || !hash_equals(hash_hmac('sha256', $parts[0], $this->cursorSecret), $parts[1])) {
            throw new \InvalidArgumentException('Invalid planning cursor.');
        }
        $decoded = base64_decode($parts[0], true);
        $data = false === $decoded ? null : json_decode($decoded, true);
        if (!\is_array($data) || ($data['scope'] ?? null) !== $scope || !\is_array($data['position'] ?? null)) {
            throw new \InvalidArgumentException('Planning cursor does not match the requested scope.');
        }
        $position = [];
        foreach ($keys as $key) {
            $value = $data['position'][$key] ?? null;
            if (!\is_string($value)) {
                throw new \InvalidArgumentException('Invalid planning cursor position.');
            }
            $position[$key] = $value;
        }

        return $position;
    }

    private function encodeCursor(string $scope, string $sku, string $date): string
    {
        $payload = base64_encode(json_encode(['scope' => $scope, 'sku' => $sku, 'date' => $date], \JSON_THROW_ON_ERROR));
        $signature = hash_hmac('sha256', $payload, $this->cursorSecret);

        return $payload.'.'.$signature;
    }

    /** @return array{sku: string, date: string} */
    private function decodeCursor(string $cursor, string $scope): array
    {
        $parts = explode('.', $cursor, 2);
        if (2 !== \count($parts) || !hash_equals(hash_hmac('sha256', $parts[0], $this->cursorSecret), $parts[1])) {
            throw new \InvalidArgumentException('Invalid planning cohort cursor.');
        }

        $decoded = base64_decode($parts[0], true);
        if (false === $decoded) {
            throw new \InvalidArgumentException('Invalid planning cohort cursor.');
        }

        $data = json_decode($decoded, true);
        if (!\is_array($data) || ($data['scope'] ?? null) !== $scope
            || !\is_string($data['sku'] ?? null) || !\is_string($data['date'] ?? null)) {
            throw new \InvalidArgumentException('Planning cohort cursor does not match the requested scope.');
        }

        return ['sku' => $data['sku'], 'date' => $data['date']];
    }
}
