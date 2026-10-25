<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\ServiceCoverage;

/**
 * Ответ сервиса на запрос покрытия. Строки и время есть только
 * у состояния Ok.
 */
final readonly class ServiceCoverageSnapshot
{
    /**
     * @param list<ServiceCoverageRow> $rows
     */
    private function __construct(
        public ServiceCoverageState $state,
        public ?\DateTimeImmutable $generatedAt,
        public int $cabinetsTotal,
        public array $rows,
    ) {
    }

    /**
     * @param list<ServiceCoverageRow> $rows
     */
    public static function ok(\DateTimeImmutable $generatedAt, int $cabinetsTotal, array $rows): self
    {
        return new self(ServiceCoverageState::Ok, $generatedAt, $cabinetsTotal, $rows);
    }

    public static function without(ServiceCoverageState $state): self
    {
        if (ServiceCoverageState::Ok === $state) {
            throw new \LogicException('An ok snapshot carries rows; use ok().');
        }

        return new self($state, null, 0, []);
    }
}
