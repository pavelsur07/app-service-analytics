<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Controller;

use App\Ingestion\Application\Dashboard\BuildDashboardSummaryAction;
use App\Ingestion\Ui\Response\Dashboard\DashboardSummaryResponse;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Сводка главной за выбранный период и период сравнения
 * (docs/plan/dashboard-widgets.md).
 *
 * Оба периода приходят явно: какой период с каким сравнивать
 * («предыдущий», «год назад», «тот же отрезок прошлого месяца») —
 * решение экрана, и бэкенд его не угадывает. Граница проверяет только
 * то, без чего ответ был бы неверным или неограниченным.
 */
#[Route(
    '/api/companies/{companyId}/dashboard/summary',
    name: 'ingestion_dashboard_summary',
    requirements: ['companyId' => Requirement::UUID],
    methods: ['GET'],
)]
final class ShowDashboardSummaryController
{
    /** Календарь площадки (ADR-009): бизнес-дата записана в нём. */
    private const string TIMEZONE = 'Europe/Moscow';

    /** Год с високосным днём — потолок длины периода (CLAUDE.md §5). */
    private const int MAX_DAYS = 366;

    private const string EARLIEST = '2020-01-01';

    public function __construct(private readonly BuildDashboardSummaryAction $buildSummary)
    {
    }

    #[OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Parameter(name: 'compareFrom', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Parameter(name: 'compareTo', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Response(response: 200, description: 'Показатели главной за период и период сравнения', content: new Model(type: DashboardSummaryResponse::class))]
    #[OA\Response(response: 403, description: 'Пользователь не состоит в компании', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 422, description: 'Некорректный период или период сравнения', content: new Model(type: ValidationErrorResponse::class))]
    public function __invoke(string $companyId, Request $request): JsonResponse
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE));
        // Последний полный день — вчера: начисления за сегодня приходят
        // неполными, и цифра выглядела бы рабочей, оставаясь неверной.
        $yesterday = $now->setTime(0, 0)->modify('-1 day');

        $from = self::date($request, 'from');
        $to = self::date($request, 'to');
        if (null === $from || null === $to || !self::validRange($from, $to, $yesterday)) {
            return self::invalid('invalid_period', 'from and to must be YYYY-MM-DD, from <= to <= yesterday, from 2020-01-01, up to 366 days.');
        }

        $compareFrom = self::date($request, 'compareFrom');
        $compareTo = self::date($request, 'compareTo');
        if (null === $compareFrom || null === $compareTo || !self::validRange($compareFrom, $compareTo, $yesterday) || $compareTo >= $from) {
            return self::invalid('invalid_comparison', 'compareFrom and compareTo must be a valid period of up to 366 days that ends before from.');
        }

        $summary = ($this->buildSummary)($companyId, $from, $to, $compareFrom, $compareTo, $now);

        return new JsonResponse(DashboardSummaryResponse::from($summary));
    }

    private static function date(Request $request, string $name): ?\DateTimeImmutable
    {
        $value = $request->query->all()[$name] ?? null;
        if (!\is_string($value) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone(self::TIMEZONE));

        // Обратное форматирование отсекает 2026-02-30: PHP молча
        // превратил бы его во 2 марта.
        return false !== $date && $date->format('Y-m-d') === $value ? $date : null;
    }

    private static function validRange(\DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $yesterday): bool
    {
        return $from <= $to
            && $to <= $yesterday
            && $from->format('Y-m-d') >= self::EARLIEST
            && $from->diff($to)->days < self::MAX_DAYS;
    }

    private static function invalid(string $code, string $message): JsonResponse
    {
        return new JsonResponse(new ValidationErrorResponse(Response::HTTP_UNPROCESSABLE_ENTITY, $code, $message), Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
