<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Persistence;

use App\Ingestion\Infrastructure\ServiceCoverage\OzonFetcherCoverageClient;
use App\Ingestion\Infrastructure\ServiceCoverage\ServiceCoverageSnapshot;
use App\Ingestion\Infrastructure\ServiceCoverage\ServiceCoverageState;
use Doctrine\DBAL\Connection;

/**
 * Запись и чтение последней сводки покрытия сервиса
 * (`service_coverage_capture`, сущность ServiceCoverageCapture).
 *
 * Запись — upsert по ключу `service` (CLAUDE.md §4, §6): повтор той же
 * попытки строку не размножает. Отказ обновляет только исход попытки,
 * последняя удачная сводка остаётся — экран показывает её со временем,
 * а не пустоту.
 */
final readonly class DbalServiceCoverageCaptureStore
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function record(string $service, ServiceCoverageSnapshot $snapshot, \DateTimeImmutable $at): void
    {
        $at = $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        if (ServiceCoverageState::Ok === $snapshot->state) {
            $this->connection->executeStatement(
                'INSERT INTO service_coverage_capture (service, state, attempted_at, succeeded_at, payload)
                 VALUES (:service, :state, :at, :at, CAST(:payload AS jsonb))
                 ON CONFLICT (service) DO UPDATE
                 SET state = EXCLUDED.state, attempted_at = EXCLUDED.attempted_at,
                     succeeded_at = EXCLUDED.succeeded_at, payload = EXCLUDED.payload',
                ['service' => $service, 'state' => $snapshot->state->value, 'at' => $at, 'payload' => $snapshot->payload],
            );

            return;
        }

        $this->connection->executeStatement(
            'INSERT INTO service_coverage_capture (service, state, attempted_at)
             VALUES (:service, :state, :at)
             ON CONFLICT (service) DO UPDATE
             SET state = EXCLUDED.state, attempted_at = EXCLUDED.attempted_at',
            ['service' => $service, 'state' => $snapshot->state->value, 'at' => $at],
        );
    }

    /**
     * null — сводку ещё ни разу не снимали.
     */
    public function latest(string $service): ?CapturedServiceCoverage
    {
        $row = $this->connection->fetchAssociative(
            'SELECT state, attempted_at, succeeded_at, payload::text AS payload
             FROM service_coverage_capture WHERE service = :service',
            ['service' => $service],
        );
        if (false === $row) {
            return null;
        }

        $state = ServiceCoverageState::tryFrom(\is_string($row['state']) ? $row['state'] : '')
            ?? throw new \UnexpectedValueException('Unexpected capture state.');
        $payload = $row['payload'];

        return new CapturedServiceCoverage(
            state: $state,
            attemptedAt: self::utc($row['attempted_at']) ?? throw new \UnexpectedValueException('Capture without attempted_at.'),
            succeededAt: self::utc($row['succeeded_at']),
            // Разбор тем же строгим кодом, что ответ сервиса: в колонке
            // лежит только то, что этот разбор уже прошло.
            lastSuccess: \is_string($payload) ? OzonFetcherCoverageClient::parse($payload) : null,
        );
    }

    private static function utc(mixed $value): ?\DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Unexpected capture timestamp.');
        }

        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }
}
