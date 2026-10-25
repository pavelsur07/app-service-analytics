<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Controller\Admin;

use App\Ingestion\Application\ServiceCoverage\CaptureServiceCoverageAction;
use App\Ingestion\Infrastructure\Persistence\DbalServiceCoverageCaptureStore;
use App\Ingestion\Infrastructure\Query\PhpDayLoadRow;
use App\Ingestion\Infrastructure\Query\PhpDayLoadsForAdminQuery;
use App\Ingestion\Infrastructure\ServiceCoverage\OzonFetcherAllowlist;
use App\Ingestion\Infrastructure\ServiceCoverage\ServiceCoverageRow;
use App\Ingestion\Infrastructure\ServiceCoverage\ServiceCoverageSnapshot;
use App\Ingestion\Ui\Response\Admin\ServiceCoverageResponse;
use App\Ingestion\Ui\Response\Admin\ServiceCoverageRowResponse;
use App\Shared\Ui\QueryParameter;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Экран «Покрытие GO» системного раздела: сводка покрытия ozon-fetcher
 * (контракт service-coverage v1, ADR-043 п. 6) рядом с загрузкой PHP
 * по тем же кабинетам.
 *
 * Сервис отсюда не вызывается (services/AGENTS.md §2): сводку снимает
 * по расписанию CaptureServiceCoverageCommand, экран читает сохранённую
 * и показывает время попытки и время сводки.
 *
 * **Межарендаторное чтение из HTTP — системный контур** (CLAUDE.md §1,
 * ADR-018): Deptrac держит класс в узком слое IngestionAdminCoverageUi,
 * и только этот слой видит PhpDayLoadsForAdminQuery. Ответ — одни
 * счётчики по ресурсу и дню: ни компаний, ни кабинетов, ни сумм.
 *
 * Обе роли контура (решение владельца 25.10.2026): экран только читает.
 */
#[Route('/api/admin/services/ozon-fetcher/coverage', name: 'ingestion_admin_service_coverage', methods: ['GET'])]
#[IsGranted('ROLE_ADMIN')]
final class GetServiceCoverageController
{
    private const string TIMEZONE = 'Europe/Moscow';

    /** Месяц и предел окна контракта (93 дня). */
    private const array ALLOWED_DAYS = [31, PhpDayLoadsForAdminQuery::MAX_DAYS];

    private const string ADS_PREFIX = 'ozon.ads.';

    public function __construct(
        private readonly DbalServiceCoverageCaptureStore $captures,
        private readonly PhpDayLoadsForAdminQuery $phpDayLoads,
        private readonly OzonFetcherAllowlist $allowlist,
    ) {
    }

    #[OA\Parameter(
        name: 'days',
        in: 'query',
        description: 'Длина окна в днях по Москве, заканчивая сегодняшним',
        required: false,
        schema: new OA\Schema(type: 'integer', default: 31, enum: [31, 93]),
    )]
    #[OA\Response(
        response: 200,
        description: 'Покрытие сервиса и загрузка PHP за окно',
        content: new Model(type: ServiceCoverageResponse::class),
    )]
    #[OA\Response(
        response: 422,
        description: 'Длина окна не 31 и не 93',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $days = QueryParameter::int($request, 'days', self::ALLOWED_DAYS[0]);
        if (!\in_array($days, self::ALLOWED_DAYS, true)) {
            return new JsonResponse(
                new ValidationErrorResponse(Response::HTTP_UNPROCESSABLE_ENTITY, 'days_invalid', 'Окно — 31 или 93 дня.'),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $today = $now->setTimezone(new \DateTimeZone(self::TIMEZONE))->setTime(0, 0);
        $from = $today->modify(\sprintf('-%d days', $days - 1))->format('Y-m-d');
        $to = $today->format('Y-m-d');

        $capture = $this->captures->latest(CaptureServiceCoverageAction::SERVICE);
        $snapshot = $capture?->lastSuccess;
        $php = $this->phpLoads($from, $to);

        return new JsonResponse(new ServiceCoverageResponse(
            from: $from,
            to: $to,
            checkedAt: $now->format(\DATE_ATOM),
            serviceState: $capture?->state->value ?? 'never',
            attemptedAt: self::atom($capture?->attemptedAt),
            serviceGeneratedAt: self::atom($snapshot?->generatedAt),
            cabinetsSeller: $snapshot?->cabinetsTotal,
            cabinetsPerformance: null !== $snapshot ? self::performanceCabinets($snapshot) : null,
            phpCompared: null !== $php,
            rows: self::rows($snapshot, $php, $from, $to),
        ));
    }

    /**
     * @return array<string, int>|null «ресурс|день» → кабинеты; null — allowlist не задан
     */
    private function phpLoads(string $from, string $to): ?array
    {
        $accounts = $this->allowlist->accountIds;
        if ([] === $accounts) {
            return null;
        }

        /** @var list<array<string, mixed>> $raw */
        $raw = $this->phpDayLoads->build($accounts, $from, $to)->executeQuery()->fetchAllAssociative();
        $loads = [];
        foreach (array_map(PhpDayLoadsForAdminQuery::mapRow(...), $raw) as $row) {
            /* @var PhpDayLoadRow $row */
            $loads[self::key($row->resourceType, $row->periodKey)] = $row->cabinets;
        }

        return $loads;
    }

    /**
     * Строка есть, если она есть у сервиса или у PHP: день, загруженный
     * одним PHP, — то самое расхождение, ради которого экран и нужен.
     *
     * @param array<string, int>|null $php null — сравнение выключено
     *
     * @return list<ServiceCoverageRowResponse>
     */
    private static function rows(?ServiceCoverageSnapshot $snapshot, ?array $php, string $from, string $to): array
    {
        $rows = [];
        foreach ($snapshot->rows ?? [] as $row) {
            // Сводка снята за 93 дня — короткое окно вырезается здесь.
            if (!self::inWindow($row->periodKey, $from, $to)) {
                continue;
            }
            $rows[self::key($row->resourceType, $row->periodKey)] = self::serviceRow($row, $php);
        }
        foreach ($php ?? [] as $key => $cabinets) {
            if (!isset($rows[$key])) {
                [$resourceType, $periodKey] = explode('|', $key, 2);
                $rows[$key] = new ServiceCoverageRowResponse($resourceType, $periodKey, null, 0, 0, 0, 0, 0, 0, null, $cabinets);
            }
        }
        ksort($rows, \SORT_STRING);

        return array_values($rows);
    }

    /**
     * @param array<string, int>|null $php null — сравнение выключено
     */
    private static function serviceRow(ServiceCoverageRow $row, ?array $php): ServiceCoverageRowResponse
    {
        // Ноль, а не null, у дня ресурса с парой в PHP, когда сравнение
        // включено: «PHP этот день не загрузил» — тоже ответ.
        $paired = null !== $php
            && 1 === preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $row->periodKey)
            && \in_array($row->resourceType, PhpDayLoadsForAdminQuery::RESOURCE_BY_REPORT_TYPE, true);
        $phpCabinets = $paired ? ($php[self::key($row->resourceType, $row->periodKey)] ?? 0) : null;

        return new ServiceCoverageRowResponse(
            resourceType: $row->resourceType,
            periodKey: $row->periodKey,
            cabinetsExpected: $row->cabinetsExpected,
            done: $row->done,
            running: $row->running,
            pending: $row->pending,
            notReady: $row->notReady,
            failedRetryable: $row->failedRetryable,
            failedPermanent: $row->failedPermanent,
            lastSuccessAt: $row->lastSuccessAt?->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
            phpCabinets: $phpCabinets,
        );
    }

    /**
     * У контракта нет отдельного счётчика кабинетов с ключом Performance:
     * он равен «ожидается» рекламных строк.
     */
    private static function performanceCabinets(ServiceCoverageSnapshot $snapshot): int
    {
        $max = 0;
        foreach ($snapshot->rows as $row) {
            if (str_starts_with($row->resourceType, self::ADS_PREFIX)) {
                $max = max($max, $row->cabinetsExpected);
            }
        }

        return $max;
    }

    /**
     * День — внутри окна; месяц — пересекается с окном; справочник — всегда.
     */
    private static function inWindow(string $periodKey, string $from, string $to): bool
    {
        return match (\strlen($periodKey)) {
            10 => $periodKey >= $from && $periodKey <= $to,
            7 => $periodKey >= substr($from, 0, 7) && $periodKey <= substr($to, 0, 7),
            default => true,
        };
    }

    private static function atom(?\DateTimeImmutable $at): ?string
    {
        return $at?->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM);
    }

    private static function key(string $resourceType, string $periodKey): string
    {
        return $resourceType.'|'.$periodKey;
    }
}
