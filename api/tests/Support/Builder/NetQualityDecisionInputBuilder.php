<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Ingestion\Application\NetForecast as Net;

final class NetQualityDecisionInputBuilder
{
    /** @var array<string, int> */
    private array $counts = [];
    /** @var array<string, list<string>> */
    private array $weeks = [];
    /** @var list<string> */
    private array $violations = [];
    private ?string $regression = null;

    public static function aDecision(): self
    {
        return new self();
    }

    public function withCount(string $group, int $count): self
    {
        $clone = clone $this;
        $clone->counts[$group] = $count;

        return $clone;
    }

    /** @param list<string> $weeks */
    public function withWeeks(string $group, array $weeks): self
    {
        $clone = clone $this;
        $clone->weeks[$group] = $weeks;

        return $clone;
    }

    public function withViolation(string $violation): self
    {
        $clone = clone $this;
        $clone->violations[] = $violation;

        return $clone;
    }

    public function withTinyRegression(string $group): self
    {
        $clone = clone $this;
        $clone->regression = $group;

        return $clone;
    }

    public function build(): Net\NetQualityDecisionInput
    {
        $groups = [];
        foreach (['overall', 'order1_2', 'order3_5', 'order6_9', 'order10_15', 'delivery0_6', 'delivery7_29', 'delivery30_59'] as $group) {
            $count = $this->counts[$group] ?? ('overall' === $group ? 100 : 30);
            $rows = [];
            for ($i = 0; $i < $count; ++$i) {
                $pair = NetQualityPairedObservationBuilder::aPair()->withMoney('RUB', ['1', '1'], ['1', '1'], ['1', '1']);
                if ($group === $this->regression) {
                    $pair = $pair->withQuantities('1000000000001', '1000000000000', '1', '1', '1', '1');
                }
                $rows[] = $pair->build();
            }
            $metrics = (new Net\NetQualityMetrics())->calculate($rows);
            $groups[$group] = new Net\NetQualityGroupResult($metrics, $this->weeks[$group] ?? ['2027-W01', '2027-W02'], $count, $count, 0, 0);
        }

        return new Net\NetQualityDecisionInput($groups, ['RUB'], $this->violations);
    }
}
