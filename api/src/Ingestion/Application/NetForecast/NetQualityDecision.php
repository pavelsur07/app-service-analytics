<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\ValueObject\NetQualityExact;

/** Pre-registered v1 release thresholds; display decimals never enter a comparison. */
final readonly class NetQualityDecision
{
    public function decide(NetQualityDecisionInput $input): NetQualityDecisionResult
    {
        $failed = [];
        $insufficient = [];
        foreach (['overall', ...NetQualityProtocol::PARAMETERS['ageGroups']] as $name) {
            $group = $input->groups[$name] ?? null;
            if (null === $group) {
                $insufficient[] = $name.'.missing';
                continue;
            }
            if ($group->metrics->count < ('overall' === $name ? 100 : 30)) {
                $insufficient[] = $name.'.sample';
            }
            if (\count($group->originWeeks) < 2) {
                $insufficient[] = $name.'.weeks';
            }
            if (0 === $group->readyCount) {
                $insufficient[] = $name.'.ready_population';
            } elseif ($group->methodNullReady > $group->simpleNullReady) {
                $failed[] = $name.'.availability';
            }
            $this->compare($group->metrics->method, $group->metrics->simple, ['mae', 'wape', 'absoluteBias'], $name, $failed, $insufficient);
        }
        $overall = $input->groups['overall'] ?? null;
        foreach ($input->currencies as $currency) {
            $money = $overall?->metrics->money[$currency] ?? null;
            if (null === $money || !\is_int($money['count'] ?? null) || $money['count'] < 30 || !\is_array($money['methodExact'] ?? null) || !\is_array($money['methodExact']['truthDenominator'] ?? null) || NetQualityExact::fromArray($money['methodExact']['truthDenominator'])->isZero()) {
                $insufficient[] = 'money.'.$currency.'.sample_or_denominator';
            }
            if (null !== $money && \is_array($money['methodRounded'] ?? null) && \is_array($money['simpleRounded'] ?? null)) {
                $this->compare($money['methodRounded'], $money['simpleRounded'], ['mae', 'absoluteBias'], 'money.'.$currency, $failed, $insufficient);
            }
        }
        $status = match (true) {
            [] !== $input->violations => 'INVALID_EVIDENCE',
            [] !== $insufficient => 'INSUFFICIENT_DATA',
            [] !== $failed => 'QUALITY_FAILED',
            default => 'QUALITY_PASSED',
        };

        return new NetQualityDecisionResult($status, array_values(array_unique($failed)), array_values(array_unique($insufficient)), $input->violations);
    }

    /** @param array<array-key, mixed> $method
     * @param array<array-key, mixed> $simple
     * @param list<string> $fields
     * @param list<string> $failed
     * @param list<string> $insufficient */
    private function compare(array $method, array $simple, array $fields, string $prefix, array &$failed, array &$insufficient): void
    {
        foreach ($fields as $field) {
            $a = $method[$field] ?? null;
            $b = $simple[$field] ?? null;
            if (!\is_array($a) || !\is_array($b)) {
                $insufficient[] = $prefix.'.'.$field.'.unavailable';
            } elseif (NetQualityExact::fromArray($a)->compare(NetQualityExact::fromArray($b)) > 0) {
                $failed[] = $prefix.'.'.$field;
            }
        }
    }
}
