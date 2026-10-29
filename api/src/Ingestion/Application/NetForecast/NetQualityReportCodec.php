<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\NetForwardArtifactKind;

final readonly class NetQualityReportCodec
{
    /** @param array<string, mixed> $report */
    public function result(array $report, NetQualityArtifactRef $reportRef, NetQualityArtifactRef $expectedDataset): NetQualityEvaluationResult
    {
        if (($report['schema'] ?? null) !== 'net_quality_report_v1' || false !== ($report['numericRolloutAllowed'] ?? null) || !\in_array($report['decision'] ?? null, ['INVALID_EVIDENCE', 'INSUFFICIENT_DATA', 'QUALITY_FAILED', 'QUALITY_PASSED'], true) || !\is_array($report['dataset'] ?? null) || !\is_array($report['counts'] ?? null)) {
            throw new \UnexpectedValueException('Invalid immutable quality report.');
        }
        $dataset = NetQualityArtifactRef::fromArray($report['dataset']);
        if (NetForwardArtifactKind::QualityDataset !== $dataset->kind || $dataset->toArray() !== $expectedDataset->toArray()) {
            throw new \UnexpectedValueException('Report dataset mismatch.');
        }
        $counts = [];
        foreach ($report['counts'] as $group => $count) {
            if (!\is_string($group) || !\is_int($count) || $count < 0 || $count > 20000) {
                throw new \UnexpectedValueException('Invalid quality count.');
            }
            $counts[$group] = $count;
        }

        return new NetQualityEvaluationResult($dataset, $reportRef, $report['decision'], $counts);
    }
}
