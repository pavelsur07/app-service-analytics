<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

final readonly class NetQualityGroupResult
{
    /** @var list<string> */
    public array $originWeeks;

    /** @param list<string> $originWeeks
     * @param array<string, int> $flow */
    public function __construct(public NetQualityMetricsResult $metrics, array $originWeeks, public int $candidateCount, public int $readyCount, public int $methodNullReady, public int $simpleNullReady, public array $flow = [])
    {
        if ($candidateCount < 0 || $candidateCount > 20000 || $readyCount < 0 || $readyCount > $candidateCount || $methodNullReady < 0 || $simpleNullReady < 0 || $methodNullReady > $readyCount || $simpleNullReady > $readyCount || $metrics->count > min($readyCount - $methodNullReady, $readyCount - $simpleNullReady)) {
            throw new \InvalidArgumentException('Inconsistent quality group flow counts.');
        }
        $weeks = [];
        foreach ($originWeeks as $week) {
            if (!\is_string($week) || 1 !== preg_match('/^[0-9]{4}-W(?:0[1-9]|[1-4][0-9]|5[0-3])$/D', $week)) {
                throw new \InvalidArgumentException('Invalid actual origin ISO week.');
            }
            $weeks[$week] = true;
        }
        $this->originWeeks = array_keys($weeks);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['metrics' => $this->metrics->toArray(), 'originWeeks' => $this->originWeeks, 'candidateCount' => $this->candidateCount, 'readyCount' => $this->readyCount, 'methodNullReady' => $this->methodNullReady, 'simpleNullReady' => $this->simpleNullReady, 'flow' => $this->flow, 'availability' => ['methodNullShare' => $this->share($this->flow['methodNullAllCandidates'] ?? 0, $this->candidateCount), 'simpleNullShare' => $this->share($this->flow['simpleNullAllCandidates'] ?? 0, $this->candidateCount), 'methodNullAmongReady' => $this->share($this->methodNullReady, $this->readyCount), 'simpleNullAmongReady' => $this->share($this->simpleNullReady, $this->readyCount)]];
    }

    /** @return array<string, mixed> */
    private function share(int $numerator, int $denominator): array
    {
        return 0 === $denominator
            ? ['value' => null, 'reason' => 'empty_population']
            : ['value' => \App\Ingestion\Domain\ValueObject\NetQualityExact::fraction((string) $numerator, (string) $denominator)->toArray(), 'reason' => null];
    }
}
