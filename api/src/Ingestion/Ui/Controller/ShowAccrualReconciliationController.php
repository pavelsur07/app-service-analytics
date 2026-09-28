<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Controller;

use App\Ingestion\Application\UnitEconomics\AccrualReconciliationGroup;
use App\Ingestion\Application\UnitEconomics\AccrualReconciliationItem;
use App\Ingestion\Application\UnitEconomics\BuildAccrualReconciliationAction;
use App\Ingestion\Ui\Response\UnitEconomics\AccrualReconciliationGroupResponse;
use App\Ingestion\Ui\Response\UnitEconomics\AccrualReconciliationResponse;
use App\Ingestion\Ui\Response\UnitEconomics\UnitEconomicsExpenseResponse;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Сверка с кабинетом: начисления Ozon за календарный месяц по группам
 * отчёта «Начисления», без товаров.
 *
 * Месяц, а не скользящее окно: выгрузку кабинета сверяют месяцами,
 * и окно в N дней не совпало бы с ней по границам (решение владельца
 * 2026-09-28).
 */
#[Route(
    '/api/companies/{companyId}/unit-economics/reconciliation',
    name: 'ingestion_unit_economics_reconciliation',
    requirements: ['companyId' => Requirement::UUID],
    methods: ['GET'],
)]
final class ShowAccrualReconciliationController
{
    private const string TIMEZONE = 'Europe/Moscow';

    /** Раньше этого месяца данных в продукте нет и быть не может. */
    private const string EARLIEST_MONTH = '2020-01';

    public function __construct(
        private readonly BuildAccrualReconciliationAction $buildReconciliation,
    ) {
    }

    #[OA\Parameter(
        name: 'month',
        in: 'query',
        description: 'Месяц начислений, Y-m; по умолчанию прошлый (по Москве)',
        required: false,
        schema: new OA\Schema(type: 'string', pattern: '^\d{4}-(0[1-9]|1[0-2])$'),
    )]
    #[OA\Response(
        response: 200,
        description: 'Начисления месяца по группам кабинета и итог к начислению',
        content: new Model(type: AccrualReconciliationResponse::class),
    )]
    #[OA\Response(
        response: 422,
        description: 'Некорректный месяц',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    #[OA\Response(
        response: 403,
        description: 'Пользователь не состоит в этой компании',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    public function __invoke(string $companyId, Request $request): JsonResponse
    {
        // Граница — по календарю площадки: бизнес-дата начисления
        // записана в часовом поясе Ozon (ADR-012).
        $moscow = new \DateTimeZone(self::TIMEZONE);
        $today = (new \DateTimeImmutable('now', $moscow))->setTime(0, 0);

        // all(), а не get(): ?month[]=… — тоже неверный месяц (422),
        // а не исключение InputBag (400).
        $month = $request->query->all()['month'] ?? $today->modify('first day of last month')->format('Y-m');
        $from = \is_string($month) && 1 === preg_match('/\A\d{4}-(0[1-9]|1[0-2])\z/', $month)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', $month.'-01', $moscow)
            : false;
        if (false === $from || !\is_string($month) || $month < self::EARLIEST_MONTH || $month > $today->format('Y-m')) {
            return new JsonResponse(
                new ValidationErrorResponse(
                    status: Response::HTTP_UNPROCESSABLE_ENTITY,
                    code: 'invalid_month',
                    message: \sprintf('month must be Y-m between %s and the current month.', self::EARLIEST_MONTH),
                ),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        // Текущий месяц — по сегодня: будущие дни не «без начислений»,
        // их просто ещё не было.
        $endOfMonth = $from->modify('last day of this month');
        $to = $endOfMonth > $today ? $today : $endOfMonth;

        $reconciliation = ($this->buildReconciliation)($companyId, $from, $to);

        return new JsonResponse(new AccrualReconciliationResponse(
            month: $month,
            from: $from->format('Y-m-d'),
            to: $to->format('Y-m-d'),
            currency: $reconciliation->currency,
            groups: array_map(
                static fn (AccrualReconciliationGroup $group): AccrualReconciliationGroupResponse => new AccrualReconciliationGroupResponse(
                    code: $group->code,
                    label: $group->label,
                    totalMinor: $group->totalMinor,
                    items: array_map(
                        static fn (AccrualReconciliationItem $item): UnitEconomicsExpenseResponse => new UnitEconomicsExpenseResponse(
                            feeTypeId: $item->feeTypeId,
                            name: $item->name,
                            amountMinor: $item->amountMinor,
                        ),
                        $group->items,
                    ),
                ),
                $reconciliation->groups,
            ),
            totalMinor: $reconciliation->totalMinor,
            daysWithoutAccruals: $reconciliation->daysWithoutAccruals,
        ));
    }
}
