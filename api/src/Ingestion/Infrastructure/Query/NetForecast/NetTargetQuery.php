<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\NetForecast;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

final readonly class NetTargetQuery
{
    public function __construct(private Connection $connection, private NetTargetLimits $limits = new NetTargetLimits())
    {
    }

    public function build(string $companyId, string $accountId, NetTrainingContext $context, NetTargetRequest $request): QueryBuilder
    {
        $context->assertScope($companyId, $accountId);
        if ([] === $request->marketplaceSkus) {
            throw new \InvalidArgumentException('Empty targets are handled before query construction.');
        }
        $ctes = NetTargetSourceSql::ctes().', '.NetTargetProofSql::ctes().', '.NetTrainingOutcomeSql::ctes().', '.self::outputCtes();
        $source = <<<SQL
            WITH {$ctes}
            SELECT s.marketplace_sku,
                   CASE WHEN NOT input.ok OR NOT output.ok THEN 'input_budget_exceeded'
                        WHEN NOT closure.valid THEN 'source_history_unavailable' ELSE NULL END AS refusal_reason,
                   CASE WHEN NOT input.ok THEN input.input WHEN NOT output.ok THEN output.input END AS budget_input,
                   CASE WHEN NOT input.ok THEN input.observed WHEN NOT output.ok THEN output.observed END AS budget_observed,
                   CASE WHEN NOT input.ok THEN input.maximum WHEN NOT output.ok THEN output.maximum END AS budget_limit,
                   output.bytes::text AS output_bytes, output.allocation::text AS allocation_bytes,
                   output.scalar_slots::text AS scalar_slots, output.containers::text AS container_nodes,
                   output.dto_nodes::text AS dto_nodes,
                   CAST(:contextFingerprint AS text) AS context_fingerprint, CAST(:epochStartExact AS text) AS epoch_start,
                   CAST(:epochEndExact AS text) AS epoch_end,
                   CASE WHEN input.ok AND output.ok AND closure.valid THEN hierarchy.days ELSE NULL END AS days
            FROM target_skus s CROSS JOIN input_budget input CROSS JOIN target_output_budget output CROSS JOIN target_closure_proof closure
            LEFT JOIN target_sku_hierarchy hierarchy USING (marketplace_sku)
            ORDER BY s.marketplace_sku LIMIT 50
            SQL;
        $query = $this->connection->createQueryBuilder()->select('result.*')->from('('.$source.')', 'result');
        $query->setParameter('contextFingerprint', $context->fingerprint())->setParameter('epochStartExact', $context->epoch->start->format('U.u'))->setParameter('epochEndExact', $context->epoch->end?->format('U.u'));

        return NetTargetSourceSql::bind($query, $context, $request, $this->limits);
    }

    private static function outputCtes(): string
    {
        return <<<'SQL'
            target_skus AS MATERIALIZED (SELECT UNNEST(ARRAY[:requestedSkus]::text[]) AS marketplace_sku),
            target_days AS MATERIALIZED (
                SELECT day::date AS business_date FROM generate_series(CAST(:targetFrom AS timestamp), CAST(:targetTo AS timestamp), INTERVAL '1 day') day
            ),
            target_part_quantities AS MATERIALIZED (
                SELECT source_row_id, COALESCE(SUM(quantity::bigint) FILTER (WHERE outcome = 'D'), 0) AS current_d,
                       COALESCE(SUM(quantity::bigint) FILTER (WHERE outcome = 'R'), 0) AS known_r,
                       COALESCE(SUM(quantity::bigint) FILTER (WHERE outcome = 'T1'), 0) AS t1,
                       COALESCE(SUM(quantity::bigint) FILTER (WHERE outcome = 'T2'), 0) AS t2,
                       COALESCE(SUM(quantity::bigint) FILTER (WHERE outcome = 'P'), 0) AS p,
                       COALESCE(SUM(quantity::bigint) FILTER (WHERE is_forecast_eligible AND handed_over_at IS NULL), 0) AS open_pre,
                       COALESCE(SUM(quantity::bigint) FILTER (WHERE is_forecast_eligible AND handed_over_at IS NOT NULL), 0) AS open_post,
                       COALESCE(SUM(quantity::bigint) FILTER (WHERE outcome IS NULL AND NOT is_forecast_eligible), 0) AS unknown,
                       SUM(quantity::bigint) AS total
                FROM canonical_outcomes GROUP BY source_row_id
            ),
            target_proven_parts AS MATERIALIZED (
                SELECT s.source_row_id, s.posting_number, s.order_number, s.business_date, s.marketplace_sku, s.quantity,
                       q.current_d, q.known_r, q.t1, q.t2, q.p, q.open_pre, q.open_post, q.unknown,
                       proof.delivery_origin, ready.age, ready.coverage_from, ready.coverage_through, ready.deep_completed_at,
                       ready.daily_complete, ready.witness_digest,
                       CASE WHEN NOT COALESCE(proof.history_valid, FALSE) OR NOT COALESCE(order_proof.history_valid, FALSE) OR returns.history_valid IS FALSE THEN 'source_history_unavailable'
                            WHEN s.quantity <= 0 OR q.total IS DISTINCT FROM s.quantity::bigint OR bad.marketplace_sku IS NOT NULL OR returns.quantity_valid IS FALSE OR returns.curve_valid IS FALSE OR delta.valid IS FALSE OR changed.source_row_id IS NOT NULL THEN 'contradictory_quantity'
                            WHEN order_proof.status_known IS FALSE OR returns.type_known IS FALSE OR q.unknown > 0 THEN 'unknown_outcome'
                            WHEN NOT COALESCE(proof.postings_complete, FALSE) OR NOT COALESCE(order_proof.postings_complete, FALSE) THEN 'postings_coverage_missing'
                            WHEN NOT COALESCE(refresh.completed_at > latest.at AND (refresh.failed_at IS NULL OR refresh.completed_at > refresh.failed_at), FALSE) THEN 'returns_coverage_missing'
                            WHEN q.current_d > 0 AND proof.delivery_origin IS NULL THEN 'delivery_observation_unknown'
                            WHEN COALESCE(returns.client_quantity, 0) > 0 AND origins.origins > 1 THEN 'ambiguous_delivery_allocation'
                            WHEN q.current_d > 0 AND ready.age BETWEEN 60 AND 66 THEN 'target_horizon_not_ready'
                            WHEN q.current_d > 0 AND NOT COALESCE(ready.daily_complete, FALSE) THEN 'returns_coverage_missing'
                            WHEN q.current_d > 0 AND ready.age >= 67 AND (ready.deep_completed_at IS NULL OR ready.coverage_through < proof.delivery_origin + INTERVAL '67 days') THEN 'target_readiness_missing'
                            ELSE NULL END AS reason,
                       s.fact_revision, s.id AS latest_revision_id, first.id AS first_revision_id,
                       first.captured_at AS initial_captured_at, s.captured_at AS latest_captured_at,
                       s.raw_document_id, raw.body_hash, s.amount_minor::text AS amount_minor, s.currency
                FROM target_latest_sales s LEFT JOIN target_part_quantities q USING (source_row_id)
                LEFT JOIN target_source_proof proof USING (source_row_id)
                LEFT JOIN target_order_proof order_proof USING (order_number)
                LEFT JOIN target_readiness ready USING (source_row_id)
                LEFT JOIN target_changed_delivery changed USING (source_row_id)
                LEFT JOIN target_group_returns returns USING (order_number, marketplace_sku)
                LEFT JOIN target_group_origins origins USING (order_number, marketplace_sku)
                LEFT JOIN target_delta_proof delta USING (order_number, marketplace_sku)
                LEFT JOIN contradictory_quantity_groups bad USING (order_number, marketplace_sku)
                LEFT JOIN sales_revisions first ON first.source_row_id = s.source_row_id AND first.fact_revision = 1
                LEFT JOIN marketplace_raw_document raw ON raw.company_id = :companyId AND raw.marketplace_account_id = :accountId AND raw.id = s.raw_document_id
                CROSS JOIN target_current_returns refresh CROSS JOIN target_input_latest latest
                WHERE s.marketplace_sku IN (:requestedSkus) AND s.business_date BETWEEN CAST(:targetFrom AS date) AND CAST(:targetTo AS date)
            ),
            target_groups AS MATERIALIZED (
                SELECT business_date, order_number, marketplace_sku, MIN(reason) AS reason,
                       COUNT(source_row_id) AS parts,
                       COALESCE(SUM(6::numeric * (2 * OCTET_LENGTH(source_row_id) + COALESCE(OCTET_LENGTH(posting_number), 0) + COALESCE(OCTET_LENGTH(order_number), 0) + OCTET_LENGTH(marketplace_sku) + COALESCE(OCTET_LENGTH(body_hash), 0) + COALESCE(OCTET_LENGTH(currency), 0) + COALESCE(OCTET_LENGTH(reason), 0) + COALESCE(OCTET_LENGTH(witness_digest), 0))), 0) AS text_bytes
                FROM target_proven_parts GROUP BY business_date, order_number, marketplace_sku
            ),
            target_return_traces AS MATERIALIZED (
                SELECT g.business_date, r.order_number, r.marketplace_sku, r.source_row_id, r.fact_revision,
                       r.id AS latest_revision_id, first.id AS first_revision_id, r.captured_at,
                       r.raw_document_id, raw.body_hash, r.return_type, r.quantity
                FROM target_groups g JOIN target_latest_returns r USING (order_number, marketplace_sku)
                LEFT JOIN return_revisions first ON first.source_row_id = r.source_row_id AND first.fact_revision = 1
                LEFT JOIN marketplace_raw_document raw ON raw.company_id = :companyId AND raw.marketplace_account_id = :accountId AND raw.id = r.raw_document_id
            ),
            target_witness_counts AS MATERIALIZED (
                SELECT p.source_row_id, COUNT(s.raw_document_id) AS statuses,
                       COALESCE(SUM(6::numeric * (OCTET_LENGTH(s.posting_number) + OCTET_LENGTH(s.raw_document_id::text))), 0) AS bytes
                FROM target_proven_parts p LEFT JOIN status_inputs s ON s.posting_number = p.posting_number GROUP BY p.source_row_id
            ),
            target_output_metrics AS MATERIALIZED (
                SELECT (SELECT COUNT(marketplace_sku) FROM target_skus)::numeric AS skus,
                       (SELECT COUNT(business_date) FROM target_days)::numeric AS days,
                       (SELECT COUNT(marketplace_sku) FROM target_groups)::numeric AS groups,
                       (SELECT COUNT(source_row_id) FROM target_proven_parts)::numeric AS parts,
                       (SELECT COUNT(source_row_id) FROM target_return_traces)::numeric AS returns,
                       COALESCE((SELECT SUM(statuses) FROM target_witness_counts), 0)::numeric AS statuses,
                       COALESCE((SELECT MAX(statuses) FROM target_witness_counts), 0) AS max_statuses,
                       COALESCE((SELECT SUM(text_bytes) FROM target_groups), 0)
                         + COALESCE((SELECT SUM(6::numeric * (OCTET_LENGTH(source_row_id) + OCTET_LENGTH(return_type) + COALESCE(OCTET_LENGTH(body_hash), 0))) FROM target_return_traces), 0)
                         + COALESCE((SELECT SUM(bytes) FROM target_witness_counts), 0)
                         + 12::numeric * COALESCE((SELECT SUM(OCTET_LENGTH(marketplace_sku)) FROM target_skus), 0) * 184 AS text_bytes
            ),
            target_output_shape AS MATERIALIZED (
                SELECT m.*, 4096::numeric * skus * days + 2048::numeric * groups + 8192::numeric * parts + 2048::numeric * returns + 1024::numeric * statuses + text_bytes AS bytes,
                       8::numeric * skus * days + 12::numeric * groups + 100::numeric * parts + 20::numeric * returns + 4::numeric * statuses AS scalar_slots,
                       3::numeric * skus * days + 6::numeric * groups + 20::numeric * parts + 2::numeric * returns + 3::numeric * statuses AS containers,
                       skus + skus * days + groups + 4::numeric * parts + returns AS dto_nodes
                FROM target_output_metrics m
            ),
            target_output_estimate AS MATERIALIZED (
                SELECT s.*, 2::numeric * bytes + 4::numeric * text_bytes + 128::numeric * scalar_slots + 1024::numeric * containers + 512::numeric * dto_nodes AS allocation
                FROM target_output_shape s
            ),
            target_output_budget AS MATERIALIZED (
                SELECT e.*, exceeded.input IS NULL AS ok, exceeded.input, exceeded.observed, exceeded.maximum
                FROM target_output_estimate e LEFT JOIN LATERAL (
                    SELECT v.input, v.observed, v.maximum FROM (VALUES
                        ('trace_statuses', e.max_statuses::numeric, 128::numeric, 1),
                        ('output_bytes', e.bytes, CAST(:maxOutputBytes AS numeric), 2),
                        ('php_allocation', e.allocation, CAST(:maxPhpAllocationBytes AS numeric), 3)
                    ) v(input, observed, maximum, priority) WHERE v.observed > v.maximum ORDER BY v.priority LIMIT 1
                ) exceeded ON TRUE
            ),
            target_status_witnesses AS MATERIALIZED (
                SELECT p.source_row_id, JSONB_AGG(JSONB_BUILD_OBJECT('postingNumber', s.posting_number, 'rawDocumentId', s.raw_document_id) ORDER BY s.observed_at, s.raw_document_id) AS ids
                FROM target_proven_parts p JOIN status_inputs s ON s.posting_number = p.posting_number
                CROSS JOIN input_budget input CROSS JOIN target_output_budget budget CROSS JOIN target_closure_proof closure
                WHERE input.ok AND budget.ok AND closure.valid GROUP BY p.source_row_id
            ),
            target_receipt_witnesses AS MATERIALIZED (
                SELECT p.source_row_id, JSONB_AGG(DISTINCT r.id::text ORDER BY r.id::text) AS ids
                FROM target_proven_parts p CROSS JOIN input_budget input CROSS JOIN target_output_budget budget CROSS JOIN target_closure_proof closure
                CROSS JOIN LATERAL (
                    (SELECT r.id FROM receipt_inputs r WHERE r.report_type = 'ozon_posting_fbo_list' AND r.status = 'completed' AND r.capture_origin = 'live_polling' AND r.capture_version = :captureVersion AND p.business_date BETWEEN r.date_from AND r.date_to ORDER BY r.marked_at DESC, r.id LIMIT 1)
                    UNION
                    (SELECT r.id FROM receipt_inputs r WHERE r.report_type = 'ozon_posting_fbo_list' AND r.status = 'completed' AND r.capture_origin = 'live_polling' AND r.capture_version = :captureVersion AND p.business_date BETWEEN r.date_from AND r.date_to AND r.marked_at > p.initial_captured_at AND r.marked_at < p.delivery_origin ORDER BY r.marked_at, r.id LIMIT 1)
                    UNION
                    (SELECT r.id FROM receipt_inputs r WHERE r.report_type = 'ozon_returns_list' AND r.status = 'completed' AND r.capture_origin = 'live_polling' AND r.capture_version = :captureVersion AND (CAST(:asOf AS timestamp) AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Moscow')::date BETWEEN r.date_from AND r.date_to ORDER BY r.marked_at DESC, r.id LIMIT 1)
                    UNION
                    (SELECT r.id FROM receipt_inputs r WHERE r.report_type = 'ozon_returns_list' AND r.marked_at = p.deep_completed_at ORDER BY r.id LIMIT 1)
                    UNION
                    (SELECT r.id FROM receipt_inputs r JOIN target_receipt_calendar c ON r.report_type = 'ozon_returns_list' AND c.day BETWEEN r.date_from AND r.date_to AND r.marked_at = c.timely_completed WHERE c.day = (p.delivery_origin AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Moscow')::date ORDER BY r.id LIMIT 1)
                    UNION
                    (SELECT r.id FROM receipt_inputs r JOIN target_receipt_calendar c ON r.report_type = 'ozon_returns_list' AND c.day BETWEEN r.date_from AND r.date_to AND r.marked_at = c.timely_completed WHERE c.day = CASE WHEN p.age < 60 THEN (CAST(:asOf AS timestamp) AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Moscow')::date - 1 ELSE ((p.delivery_origin + INTERVAL '67 days') AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Moscow')::date END ORDER BY r.id LIMIT 1)
                ) r WHERE input.ok AND budget.ok AND closure.valid GROUP BY p.source_row_id
            ),
            target_part_json AS MATERIALIZED (
                SELECT p.business_date, p.order_number, p.marketplace_sku, p.source_row_id,
                       JSONB_BUILD_OBJECT('sourceRowId', p.source_row_id, 'postingNumber', p.posting_number, 'rawQuantity', p.quantity,
                         'currentD', CASE WHEN p.reason IS NULL THEN p.current_d END, 'knownR', CASE WHEN p.reason IS NULL THEN p.known_r END,
                         't1', CASE WHEN p.reason IS NULL THEN p.t1 END, 't2', CASE WHEN p.reason IS NULL THEN p.t2 END, 'p', CASE WHEN p.reason IS NULL THEN p.p END,
                         'eligibleOpenPre', CASE WHEN p.reason IS NULL THEN p.open_pre END, 'eligibleOpenPost', CASE WHEN p.reason IS NULL THEN p.open_post END,
                         'unknownQuantity', CASE WHEN p.reason IS NULL THEN p.unknown END, 'deliveryOrigin', p.delivery_origin,
                         'readiness', CASE WHEN p.current_d > 0 AND p.age >= 67 AND p.reason IS NULL THEN JSONB_BUILD_OBJECT(
                           'orderNumber', p.order_number, 'marketplaceSku', p.marketplace_sku, 'deliveryOrigin', p.delivery_origin,
                           'coveragePolicy', 'daily_complete_receipts_v1', 'coverageFrom', p.coverage_from, 'coverageThrough', p.coverage_through,
                           'deepCompletedAt', p.deep_completed_at, 'historyComplete', TRUE, 'postingsCoverageComplete', TRUE, 'returnsCoverageComplete', TRUE) END,
                         'inputComplete', p.reason IS NULL, 'unavailableReason', p.reason,
                         'trace', JSONB_BUILD_OBJECT('sourceRowId', p.source_row_id, 'firstRevisionId', p.first_revision_id, 'latestRevisionId', p.latest_revision_id,
                           'latestFactRevision', p.fact_revision, 'initialCapturedAt', p.initial_captured_at, 'latestCapturedAt', p.latest_captured_at,
                           'rawDocumentId', p.raw_document_id, 'rawBodyHash', p.body_hash, 'amountMinor', p.amount_minor, 'currency', p.currency,
                           'statusWitnessIds', COALESCE(statuses.ids, '[]'::jsonb), 'receiptWitnessIds', COALESCE(receipts.ids, '[]'::jsonb), 'coverageWitnessDigest', p.witness_digest)) AS part
                FROM target_proven_parts p LEFT JOIN target_status_witnesses statuses USING (source_row_id) LEFT JOIN target_receipt_witnesses receipts USING (source_row_id)
                CROSS JOIN input_budget input CROSS JOIN target_output_budget budget CROSS JOIN target_closure_proof closure WHERE input.ok AND budget.ok AND closure.valid
            ),
            target_return_json AS MATERIALIZED (
                SELECT business_date, order_number, marketplace_sku, JSONB_AGG(JSONB_BUILD_OBJECT('sourceRowId', source_row_id,
                    'firstRevisionId', first_revision_id, 'latestRevisionId', latest_revision_id, 'latestFactRevision', fact_revision,
                    'latestCapturedAt', captured_at, 'rawDocumentId', raw_document_id, 'rawBodyHash', body_hash, 'returnType', return_type, 'quantity', quantity) ORDER BY source_row_id) AS traces
                FROM target_return_traces CROSS JOIN input_budget input CROSS JOIN target_output_budget budget CROSS JOIN target_closure_proof closure
                WHERE input.ok AND budget.ok AND closure.valid GROUP BY business_date, order_number, marketplace_sku
            ),
            target_group_json AS MATERIALIZED (
                SELECT g.business_date, g.order_number, g.marketplace_sku,
                       JSONB_BUILD_OBJECT('orderNumber', g.order_number, 'marketplaceSku', g.marketplace_sku, 'businessDate', g.business_date,
                         'parts', JSONB_AGG(p.part ORDER BY p.source_row_id), 'returns', COALESCE(r.traces, '[]'::jsonb),
                         'inputComplete', g.reason IS NULL, 'unavailableReason', g.reason) AS value
                FROM target_groups g JOIN target_part_json p ON p.business_date = g.business_date AND p.order_number IS NOT DISTINCT FROM g.order_number AND p.marketplace_sku = g.marketplace_sku
                LEFT JOIN target_return_json r ON r.business_date = g.business_date AND r.order_number IS NOT DISTINCT FROM g.order_number AND r.marketplace_sku = g.marketplace_sku
                GROUP BY g.business_date, g.order_number, g.marketplace_sku, g.reason, r.traces
            ),
            target_day_json AS MATERIALIZED (
                SELECT s.marketplace_sku, d.business_date,
                       JSONB_BUILD_OBJECT('businessDate', d.business_date, 'groups', COALESCE(JSONB_AGG(g.value ORDER BY g.order_number NULLS FIRST) FILTER (WHERE g.value IS NOT NULL), '[]'::jsonb),
                         'emptyComplete', COUNT(g.value) = 0 AND COALESCE(postings.completed_at >= ((d.business_date + 1)::timestamp AT TIME ZONE 'Europe/Moscow' AT TIME ZONE 'UTC') AND (postings.failed_at IS NULL OR postings.completed_at > postings.failed_at)
                           AND refresh.completed_at IS NOT NULL AND (refresh.failed_at IS NULL OR refresh.completed_at > refresh.failed_at), FALSE),
                         'unavailableReason', CASE WHEN COUNT(g.value) > 0 THEN NULL
                           WHEN NOT COALESCE(postings.completed_at >= ((d.business_date + 1)::timestamp AT TIME ZONE 'Europe/Moscow' AT TIME ZONE 'UTC') AND (postings.failed_at IS NULL OR postings.completed_at > postings.failed_at), FALSE) THEN 'postings_coverage_missing'
                           WHEN NOT COALESCE(refresh.completed_at IS NOT NULL AND (refresh.failed_at IS NULL OR refresh.completed_at > refresh.failed_at), FALSE) THEN 'returns_coverage_missing' END) AS value
                FROM target_skus s CROSS JOIN target_days d CROSS JOIN target_current_returns refresh
                CROSS JOIN input_budget input CROSS JOIN target_output_budget budget CROSS JOIN target_closure_proof closure
                LEFT JOIN target_group_json g ON g.marketplace_sku = s.marketplace_sku AND g.business_date = d.business_date
                LEFT JOIN posting_receipts postings ON postings.business_date = d.business_date
                WHERE input.ok AND budget.ok AND closure.valid
                GROUP BY s.marketplace_sku, d.business_date, postings.completed_at, postings.failed_at, refresh.completed_at, refresh.failed_at
            ),
            target_sku_hierarchy AS MATERIALIZED (
                SELECT marketplace_sku, JSONB_AGG(value ORDER BY business_date) AS days FROM target_day_json GROUP BY marketplace_sku
            )
            SQL;
    }

    /** @param list<array<string, mixed>> $rows */
    public function mapRows(NetTrainingContext $context, NetTargetRequest $request, array $rows): NetTargetBatch
    {
        if ([] === $request->marketplaceSkus) {
            throw new \InvalidArgumentException('Empty target mapping is not a zero shortcut.');
        }
        if (null === $context->snapshotId) {
            return new NetTargetBatch($context, [], 'target_snapshot_missing');
        }
        $started = hrtime(true);
        $allocation = 0;
        $failure = null;
        try {
            // Validate the scalar envelope for every SKU before the first decode.
            $seen = [];
            $rawBytes = 0;
            foreach ($rows as $envelope) {
                if (($envelope['context_fingerprint'] ?? null) !== $context->fingerprint()
                    || ($envelope['epoch_start'] ?? null) !== $context->epoch->start->format('U.u')
                    || ($envelope['epoch_end'] ?? null) !== $context->epoch->end?->format('U.u')) {
                    return new NetTargetBatch($context, [], 'training_context_mismatch');
                }
                if (null !== ($envelope['refusal_reason'] ?? null)) {
                    return new NetTargetBatch($context, [], self::string($envelope['refusal_reason']), NetTrainingValue::budgetFailure($envelope));
                }
                $sku = self::string($envelope['marketplace_sku'] ?? null);
                if (!\in_array($sku, $request->marketplaceSkus, true) || isset($seen[$sku])) {
                    throw new \UnexpectedValueException('Foreign or duplicate target SKU.');
                }
                $seen[$sku] = true;
                $bytes = NetTrainingValue::integer($envelope['output_bytes'] ?? null);
                $next = NetTrainingValue::integer($envelope['allocation_bytes'] ?? null);
                foreach (['scalar_slots', 'container_nodes', 'dto_nodes'] as $counter) {
                    NetTrainingValue::integer($envelope[$counter] ?? null);
                }
                if ($bytes > $this->limits->outputBytes || $next > $this->limits->phpAllocationBytes) {
                    return new NetTargetBatch($context, [], 'input_budget_exceeded', new NetTrainingBudgetFailure('output_estimate', max($bytes, $next), min($this->limits->outputBytes, $this->limits->phpAllocationBytes)));
                }
                $raw = self::string($envelope['days'] ?? null);
                $rawBytes += \strlen($raw);
                if ($rawBytes > $bytes || $rawBytes > $this->limits->outputBytes) {
                    return new NetTargetBatch($context, [], 'input_budget_exceeded', new NetTrainingBudgetFailure('output_bytes', $rawBytes, min($bytes, $this->limits->outputBytes)));
                }
                $allocation = max($allocation, $next);
            }
            if (\count($seen) !== \count($request->marketplaceSkus)) {
                throw new \UnexpectedValueException('Missing requested target SKU.');
            }
            $this->guard($started, $allocation);
            $skus = [];
            $reason = null;
            $groupsTotal = 0;
            $sourceKeys = [];
            foreach ($rows as $index => $row) {
                $this->guard($started, $allocation);
                $decoded = json_decode(self::string($row['days']), true, 24, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
                unset($rows[$index]);
                $dayRows = self::list($decoded);
                $expected = $request->accumulationFrom ?? $request->from;
                $dayCount = (int) $expected->diff($request->to)->format('%a') + 1;
                if (\count($dayRows) !== $dayCount) {
                    throw new \UnexpectedValueException('Incomplete target day spine.');
                }
                $days = [];
                foreach ($dayRows as $day) {
                    $this->guard($started, $allocation);
                    $d = self::object($day, ['businessDate', 'groups', 'emptyComplete', 'unavailableReason']);
                    $date = self::string($d['businessDate'] ?? null);
                    if ($date !== $expected->format('Y-m-d')) {
                        throw new \UnexpectedValueException('Wrong, missing or duplicate target day.');
                    }
                    $expected = $expected->modify('+1 day');
                    $groupRows = self::list($d['groups'] ?? null);
                    $groupsTotal += \count($groupRows);
                    if (\count($groupRows) > $this->limits->groupsPerSkuDay || $groupsTotal > $this->limits->groups) {
                        $failure = \count($groupRows) > $this->limits->groupsPerSkuDay
                            ? new NetTrainingBudgetFailure('groups_per_sku_day', \count($groupRows), $this->limits->groupsPerSkuDay)
                            : new NetTrainingBudgetFailure('groups', $groupsTotal, $this->limits->groups);
                        throw new \OverflowException($failure->input);
                    }
                    $groups = [];
                    $identities = [];
                    foreach ($groupRows as $group) {
                        $this->guard($started, $allocation);
                        $g = self::object($group, ['orderNumber', 'marketplaceSku', 'businessDate', 'parts', 'returns', 'inputComplete', 'unavailableReason']);
                        $order = NetTrainingValue::nullableString($g['orderNumber'] ?? null);
                        if (null !== $order && '' === trim($order)) {
                            throw new \UnexpectedValueException('Empty target order identity.');
                        }
                        if (($g['marketplaceSku'] ?? null) !== $row['marketplace_sku'] || ($g['businessDate'] ?? null) !== $date) {
                            throw new \UnexpectedValueException('Foreign target group identity.');
                        }
                        $identity = null === $order ? 'missing' : 'order:'.$order;
                        if (isset($identities[$identity])) {
                            throw new \UnexpectedValueException('Duplicate target group.');
                        }
                        $identities[$identity] = true;
                        $parts = [];
                        $localKeys = [];
                        foreach (self::list($g['parts'] ?? null) as $part) {
                            $this->guard($started, $allocation);
                            $v = self::object($part, ['sourceRowId', 'postingNumber', 'rawQuantity', 'currentD', 'knownR', 't1', 't2', 'p', 'eligibleOpenPre', 'eligibleOpenPost', 'unknownQuantity', 'deliveryOrigin', 'readiness', 'trace', 'inputComplete', 'unavailableReason']);
                            $key = self::string($v['sourceRowId'] ?? null);
                            if (isset($localKeys['sale:'.$key]) || '' === $key) {
                                throw new \UnexpectedValueException('Duplicate or empty target source.');
                            }
                            $localKeys['sale:'.$key] = true;
                            $sourceKeys['sale:'.$key] = true;
                            $trace = self::object($v['trace'] ?? null, ['sourceRowId', 'firstRevisionId', 'latestRevisionId', 'latestFactRevision', 'initialCapturedAt', 'latestCapturedAt', 'rawDocumentId', 'rawBodyHash', 'amountMinor', 'currency', 'statusWitnessIds', 'receiptWitnessIds', 'coverageWitnessDigest']);
                            if (($trace['sourceRowId'] ?? null) !== $key) {
                                throw new \UnexpectedValueException('Foreign target trace.');
                            }
                            $statusIds = [];
                            foreach (self::list($trace['statusWitnessIds'] ?? null) as $witness) {
                                $w = self::object($witness, ['postingNumber', 'rawDocumentId']);
                                $statusIds[] = ['postingNumber' => self::string($w['postingNumber'] ?? null), 'rawDocumentId' => self::string($w['rawDocumentId'] ?? null)];
                            }
                            $receiptIds = array_map(self::string(...), self::list($trace['receiptWitnessIds'] ?? null));
                            if (\count($statusIds) > 128 || \count($receiptIds) > 128) {
                                $failure = new NetTrainingBudgetFailure('trace_witnesses', max(\count($statusIds), \count($receiptIds)), 128);
                                throw new \OverflowException($failure->input);
                            }
                            $amount = NetTrainingValue::nullableString($trace['amountMinor'] ?? null);
                            if (null !== $amount && 1 !== preg_match('/^-?(0|[1-9][0-9]*)$/D', $amount)) {
                                throw new \UnexpectedValueException('Non-integer original price.');
                            }
                            $sourceTrace = new NetTargetSourceTrace($key, NetTrainingValue::nullableString($trace['firstRevisionId'] ?? null), NetTrainingValue::nullableString($trace['latestRevisionId'] ?? null), NetTrainingValue::integer($trace['latestFactRevision'] ?? null), self::instant($trace['initialCapturedAt'] ?? null), self::instant($trace['latestCapturedAt'] ?? null), self::string($trace['rawDocumentId'] ?? null), NetTrainingValue::nullableString($trace['rawBodyHash'] ?? null), $amount, NetTrainingValue::nullableString($trace['currency'] ?? null), $statusIds, $receiptIds, NetTrainingValue::nullableString($trace['coverageWitnessDigest'] ?? null));
                            if ((null !== $sourceTrace->latestCapturedAt && $sourceTrace->latestCapturedAt > $context->readAt)
                                || (null !== $sourceTrace->initialCapturedAt && (null === $sourceTrace->latestCapturedAt || $sourceTrace->initialCapturedAt > $sourceTrace->latestCapturedAt))) {
                                throw new \UnexpectedValueException('Invalid source observation bounds.');
                            }
                            $origin = self::instant($v['deliveryOrigin'] ?? null);
                            $readiness = null;
                            if (null !== ($v['readiness'] ?? null)) {
                                $r = self::object($v['readiness'], ['orderNumber', 'marketplaceSku', 'deliveryOrigin', 'coveragePolicy', 'coverageFrom', 'coverageThrough', 'deepCompletedAt', 'historyComplete', 'postingsCoverageComplete', 'returnsCoverageComplete']);
                                $readinessOrigin = self::instant($r['deliveryOrigin'] ?? null);
                                $from = self::instant($r['coverageFrom'] ?? null);
                                $through = self::instant($r['coverageThrough'] ?? null);
                                if (($r['orderNumber'] ?? null) !== $order || ($r['marketplaceSku'] ?? null) !== $row['marketplace_sku'] || null === $origin || $readinessOrigin?->format('U.u') !== $origin->format('U.u') || null === $from || null === $through) {
                                    throw new \UnexpectedValueException('Foreign readiness proof.');
                                }
                                $readiness = new NetTargetReadinessRow($context, self::string($r['orderNumber']), self::string($r['marketplaceSku']), $origin, self::string($r['coveragePolicy'] ?? null), $from, $through, self::instant($r['deepCompletedAt'] ?? null), NetTrainingValue::boolean($r['historyComplete'] ?? null), NetTrainingValue::boolean($r['postingsCoverageComplete'] ?? null), NetTrainingValue::boolean($r['returnsCoverageComplete'] ?? null));
                            }
                            $complete = NetTrainingValue::boolean($v['inputComplete'] ?? null);
                            $partReason = NetTrainingValue::nullableString($v['unavailableReason'] ?? null);
                            if ($complete !== (null === $partReason) || $complete && null === $order) {
                                throw new \UnexpectedValueException('Invalid target completeness.');
                            }
                            $quantities = [];
                            foreach (['currentD', 'knownR', 't1', 't2', 'p', 'eligibleOpenPre', 'eligibleOpenPost', 'unknownQuantity'] as $quantity) {
                                if (!\array_key_exists($quantity, $v)) {
                                    throw new \UnexpectedValueException('Missing target quantity.');
                                }
                                $quantities[] = NetTrainingValue::nullableInteger($v[$quantity]);
                            }
                            $rawQuantity = self::signedInteger($v['rawQuantity'] ?? null);
                            if ($complete) {
                                $remaining = $rawQuantity;
                                foreach ($quantities as $quantity) {
                                    if (null === $quantity || $quantity > $remaining) {
                                        throw new \UnexpectedValueException('Non-conserved target quantity.');
                                    }
                                    $remaining -= $quantity;
                                }
                                if (0 !== $remaining || $rawQuantity < 1) {
                                    throw new \UnexpectedValueException('Non-conserved target quantity.');
                                }
                            } elseif ([] !== array_filter($quantities, static fn (?int $value): bool => null !== $value)) {
                                throw new \UnexpectedValueException('Unavailable target must not expose partial quantities.');
                            }
                            $parts[] = new NetTargetPartRow($key, NetTrainingValue::nullableString($v['postingNumber'] ?? null), $rawQuantity, ...[...$quantities, $origin, $readiness, $sourceTrace, $complete, $partReason]);
                            $reason ??= $partReason;
                        }
                        if ([] === $parts) {
                            throw new \UnexpectedValueException('Empty target group.');
                        }
                        $returns = [];
                        foreach (self::list($g['returns'] ?? null) as $return) {
                            $this->guard($started, $allocation);
                            $r = self::object($return, ['sourceRowId', 'firstRevisionId', 'latestRevisionId', 'latestFactRevision', 'latestCapturedAt', 'rawDocumentId', 'rawBodyHash', 'returnType', 'quantity']);
                            $key = self::string($r['sourceRowId'] ?? null);
                            if (isset($localKeys['return:'.$key])) {
                                throw new \UnexpectedValueException('Duplicate target return trace.');
                            }
                            $localKeys['return:'.$key] = true;
                            $sourceKeys['return:'.$key] = true;
                            $returns[] = new NetTargetReturnTrace($key, NetTrainingValue::nullableString($r['firstRevisionId'] ?? null), NetTrainingValue::nullableString($r['latestRevisionId'] ?? null), NetTrainingValue::integer($r['latestFactRevision'] ?? null), self::instant($r['latestCapturedAt'] ?? null), self::string($r['rawDocumentId'] ?? null), NetTrainingValue::nullableString($r['rawBodyHash'] ?? null), self::string($r['returnType'] ?? null), self::signedInteger($r['quantity'] ?? null));
                        }
                        if (\count($localKeys) > $this->limits->sourcesPerGroup || \count($sourceKeys) > $this->limits->sourceKeys) {
                            $failure = \count($localKeys) > $this->limits->sourcesPerGroup
                                ? new NetTrainingBudgetFailure('sources_per_group', \count($localKeys), $this->limits->sourcesPerGroup)
                                : new NetTrainingBudgetFailure('source_keys', \count($sourceKeys), $this->limits->sourceKeys);
                            throw new \OverflowException($failure->input);
                        }
                        $groupComplete = NetTrainingValue::boolean($g['inputComplete'] ?? null);
                        $groupReason = NetTrainingValue::nullableString($g['unavailableReason'] ?? null);
                        if ($groupComplete !== (null === $groupReason) || $groupComplete && null !== $partReason) {
                            throw new \UnexpectedValueException('Invalid target group completeness.');
                        }
                        $groups[] = new NetTargetGroupRow($order, self::string($g['marketplaceSku']), $date, $parts, $returns, $groupComplete, $groupReason);
                        $reason ??= $groupReason;
                    }
                    $empty = NetTrainingValue::boolean($d['emptyComplete'] ?? null);
                    $dayReason = NetTrainingValue::nullableString($d['unavailableReason'] ?? null);
                    if ([] === $groups && $empty !== (null === $dayReason) || [] !== $groups && $empty) {
                        throw new \UnexpectedValueException('Unproven target empty day.');
                    }
                    $days[] = new NetTargetDayRow($date, $groups, $empty, $dayReason);
                    $reason ??= $dayReason;
                }
                $skus[] = new NetTargetSkuRow(self::string($row['marketplace_sku']), $days);
                unset($decoded, $dayRows);
            }
            $this->guard($started, 0);

            return new NetTargetBatch($context, $skus, $reason);
        } catch (\OverflowException $error) {
            $input = $error->getMessage();

            $failure ??= 'php_memory' === $input
                ? new NetTrainingBudgetFailure($input, max(memory_get_peak_usage(true), memory_get_usage(true) + $allocation + 8388608), $this->limits->phpPeakBytes)
                : new NetTrainingBudgetFailure($input, hrtime(true) - $started, (int) ($this->limits->computeSeconds * 1000000000));

            return new NetTargetBatch($context, [], 'input_budget_exceeded', $failure);
        } catch (\UnexpectedValueException|\JsonException|\InvalidArgumentException) {
            return new NetTargetBatch($context, [], 'source_history_unavailable');
        }
    }

    private function guard(int $started, int $allocation): void
    {
        if (memory_get_usage(true) + $allocation + 8388608 > $this->limits->phpPeakBytes || memory_get_peak_usage(true) > $this->limits->phpPeakBytes) {
            throw new \OverflowException('php_memory');
        }
        if (hrtime(true) - $started > $this->limits->computeSeconds * 1000000000) {
            throw new \OverflowException('compute_deadline');
        }
    }

    private static function string(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Expected target string.');
        }

        return $value;
    }

    /** @return list<mixed> */
    private static function list(mixed $value): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException('Expected bounded target list.');
        }

        return $value;
    }

    /**
     * @param list<string> $fields
     *
     * @return array<string, mixed>
     */
    private static function object(mixed $value, array $fields): array
    {
        if (!\is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException('Expected target object.');
        }
        if (\count($fields) !== \count($value) || [] !== array_diff($fields, array_keys($value))) {
            throw new \UnexpectedValueException('Unexpected target object shape.');
        }
        $object = [];
        foreach ($value as $key => $item) {
            if (!\is_string($key)) {
                throw new \UnexpectedValueException('Expected target object fields.');
            }
            $object[$key] = $item;
        }

        return $object;
    }

    private static function signedInteger(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && 1 === preg_match('/^-?(0|[1-9][0-9]*)$/D', $value)) {
            $integer = filter_var($value, \FILTER_VALIDATE_INT);
            if (false !== $integer) {
                return $integer;
            }
        }

        throw new \UnexpectedValueException('Expected exact target integer.');
    }

    private static function instant(mixed $value): ?\DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }
        $text = self::string($value);
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/D', $text)) {
            throw new \UnexpectedValueException('Expected UTC source timestamp.');
        }

        $normalized = str_replace('T', ' ', $text);
        $normalized = str_contains($normalized, '.') ? str_pad($normalized, 26, '0') : $normalized.'.000000';
        $instant = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $normalized, new \DateTimeZone('UTC'));
        if (false === $instant || $instant->format('Y-m-d H:i:s.u') !== $normalized) {
            throw new \UnexpectedValueException('Invalid source timestamp.');
        }

        return $instant;
    }
}
