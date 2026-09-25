<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Controller;

use App\Ingestion\Application\Coverage\BuildDataCoverageAction;
use App\Ingestion\Domain\Coverage\DataCoverageRow;
use App\Ingestion\Domain\Coverage\DataCoverageStatus;
use App\Ingestion\Ui\Response\Coverage\DataCoverageResponse;
use App\Ingestion\Ui\Response\Coverage\DataCoverageRowResponse;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Отчёт о полноте данных кабинета за месяц: по строке на эндпоинт Ozon,
 * по ячейке на день, плюс «Итого». Членство в компании проверяет
 * `CompanyAccessSubscriber`, принадлежность кабинета компании — сценарий:
 * чужой кабинет — 404.
 */
#[Route(
    '/api/companies/{companyId}/connections/{marketplaceAccountId}/coverage',
    name: 'ingestion_connection_coverage',
    requirements: ['companyId' => Requirement::UUID, 'marketplaceAccountId' => Requirement::UUID],
    methods: ['GET'],
)]
final class GetDataCoverageController
{
    private const string TIMEZONE = 'Europe/Moscow';

    /** Раньше этого месяца данных в продукте нет и быть не может. */
    private const string EARLIEST_MONTH = '2020-01';

    public function __construct(
        private readonly BuildDataCoverageAction $buildCoverage,
    ) {
    }

    #[OA\Parameter(
        name: 'month',
        in: 'query',
        description: 'Месяц отчёта, Y-m; по умолчанию текущий (по Москве)',
        required: false,
        schema: new OA\Schema(type: 'string', pattern: '^\d{4}-(0[1-9]|1[0-2])$'),
    )]
    #[OA\Response(
        response: 200,
        description: 'Покрытие дней месяца выгрузками по эндпоинтам',
        content: new Model(type: DataCoverageResponse::class),
    )]
    #[OA\Response(
        response: 422,
        description: 'Некорректный месяц',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    #[OA\Response(
        response: 404,
        description: 'Подключение не найдено в этой компании',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    #[OA\Response(
        response: 403,
        description: 'Пользователь не состоит в этой компании',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    public function __invoke(string $companyId, string $marketplaceAccountId, Request $request): JsonResponse
    {
        $moscow = new \DateTimeZone(self::TIMEZONE);
        $today = (new \DateTimeImmutable('now', $moscow))->setTime(0, 0);

        // all(), а не get(): ?month[]=… — тоже неверный месяц (422),
        // а не исключение InputBag (400).
        $month = $request->query->all()['month'] ?? $today->format('Y-m');
        $monthStart = \is_string($month) && 1 === preg_match('/\A\d{4}-(0[1-9]|1[0-2])\z/', $month)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', $month.'-01', $moscow)
            : false;
        if (false === $monthStart || !\is_string($month) || $month < self::EARLIEST_MONTH || $month > $today->format('Y-m')) {
            return $this->error(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'invalid_month',
                \sprintf('month must be Y-m between %s and the current month.', self::EARLIEST_MONTH),
            );
        }

        $coverage = ($this->buildCoverage)($companyId, $marketplaceAccountId, $monthStart, $today);
        if (null === $coverage) {
            return $this->error(Response::HTTP_NOT_FOUND, 'connection_not_found', 'Подключение не найдено.');
        }

        return new JsonResponse(new DataCoverageResponse(
            month: $month,
            today: $today->format('Y-m-d'),
            days: array_map(static fn (\DateTimeImmutable $day): string => $day->format('Y-m-d'), $coverage->days),
            total: self::row($coverage->total),
            rows: array_map(self::row(...), $coverage->rows),
        ));
    }

    private static function row(DataCoverageRow $row): DataCoverageRowResponse
    {
        return new DataCoverageRowResponse(
            key: $row->source->reportType ?? 'total',
            section: $row->source->section ?? 'Итого',
            endpoint: $row->source->endpoint ?? '',
            statuses: array_map(static fn (DataCoverageStatus $status): string => $status->value, $row->statuses),
            lastReceivedAt: array_map(
                static fn (?\DateTimeImmutable $at): ?string => $at?->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
                $row->lastReceivedAt,
            ),
            covered: $row->covered,
            due: $row->due,
        );
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(new ValidationErrorResponse($status, $code, $message), $status);
    }
}
