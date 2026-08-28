<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Controller;

use App\Ingestion\Application\BuildUnitEconomicsAction;
use App\Ingestion\Application\UnitEconomicsExpense;
use App\Ingestion\Application\UnitEconomicsSku;
use App\Ingestion\Infrastructure\Query\UnitEconomicsCursor;
use App\Ingestion\Infrastructure\Query\UnitEconomicsDirection;
use App\Ingestion\Infrastructure\Query\UnitEconomicsQuery;
use App\Ingestion\Infrastructure\Query\UnitEconomicsSort;
use App\Ingestion\Ui\Response\UnitEconomicsExpenseResponse;
use App\Ingestion\Ui\Response\UnitEconomicsResponse;
use App\Ingestion\Ui\Response\UnitEconomicsSkuResponse;
use App\Shared\Ui\QueryParameter;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Юнит-экономика за период: по товарам и отдельно по кабинету.
 *
 * Общие расходы — реклама, хранение — не размазываются по товарам
 * (ADR-012): базис распределения захочется менять, а показанная строка
 * «расходы кабинета» честнее доли, происхождение которой клиент
 * не проверит.
 */
#[Route(
    '/api/companies/{companyId}/unit-economics',
    name: 'ingestion_unit_economics',
    requirements: ['companyId' => Requirement::UUID],
    methods: ['GET'],
)]
final class ShowUnitEconomicsController
{
    private const int DEFAULT_DAYS = 30;
    private const int DEFAULT_LIMIT = UnitEconomicsQuery::DEFAULT_LIMIT;
    private const int MAX_LIMIT = UnitEconomicsQuery::MAX_LIMIT;
    private const int MAX_DAYS = 366;
    private const string TIMEZONE = 'Europe/Moscow';

    public function __construct(
        private readonly BuildUnitEconomicsAction $buildReport,
    ) {
    }

    #[OA\Parameter(
        name: 'days',
        in: 'query',
        description: 'Окно в днях по бизнес-дате площадки',
        required: false,
        schema: new OA\Schema(type: 'integer', default: self::DEFAULT_DAYS, maximum: self::MAX_DAYS, minimum: 1),
    )]
    #[OA\Parameter(
        name: 'limit',
        in: 'query',
        description: 'Сколько товаров вернуть на странице',
        required: false,
        schema: new OA\Schema(type: 'integer', default: self::DEFAULT_LIMIT, maximum: self::MAX_LIMIT, minimum: 1),
    )]
    #[OA\Parameter(
        name: 'sort',
        in: 'query',
        description: 'Показатель, по которому упорядочена страница',
        required: false,
        schema: new OA\Schema(
            type: 'string',
            default: 'revenue',
            enum: ['delivered', 'revenue', 'commission', 'expenses', 'cost', 'margin'],
        ),
    )]
    #[OA\Parameter(
        name: 'direction',
        in: 'query',
        description: 'Направление сортировки',
        required: false,
        schema: new OA\Schema(type: 'string', default: 'desc', enum: ['asc', 'desc']),
    )]
    #[OA\Parameter(
        name: 'cursor',
        in: 'query',
        description: 'Курсор следующей страницы из предыдущего ответа. Действителен только для той сортировки, при которой выдан',
        required: false,
        schema: new OA\Schema(type: 'string'),
    )]
    #[OA\Response(
        response: 200,
        description: 'Экономика по артикулам и расходы кабинета за период',
        content: new Model(type: UnitEconomicsResponse::class),
    )]
    #[OA\Response(
        response: 422,
        description: 'Некорректные days, limit, sort, direction или cursor',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    #[OA\Response(
        response: 403,
        description: 'Пользователь не состоит в этой компании',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    public function __invoke(string $companyId, Request $request): JsonResponse
    {
        $days = QueryParameter::int($request, 'days', self::DEFAULT_DAYS);
        if (null === $days || $days < 1 || $days > self::MAX_DAYS) {
            return new JsonResponse(
                new ValidationErrorResponse(
                    status: Response::HTTP_UNPROCESSABLE_ENTITY,
                    code: 'invalid_days',
                    message: \sprintf('days must be an integer between 1 and %d.', self::MAX_DAYS),
                ),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        // Граница считается в часовом поясе площадки, а не через
        // CURRENT_DATE в SQL: бизнес-дата записана по календарю Ozon,
        // и рядом с полуночью окно съезжало бы на сутки.
        $to = (new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE)))->setTime(0, 0);
        $from = $to->modify(\sprintf('-%d day', $days - 1));

        $limit = QueryParameter::int($request, 'limit', self::DEFAULT_LIMIT);
        if (null === $limit || $limit < 1 || $limit > self::MAX_LIMIT) {
            // Превышение потолка — 422, а не тихая обрезка до максимума
            // (§5): клиент, попросивший тысячу строк, должен узнать,
            // что получил не тысячу.
            return new JsonResponse(
                new ValidationErrorResponse(
                    status: Response::HTTP_UNPROCESSABLE_ENTITY,
                    code: 'invalid_limit',
                    message: \sprintf('limit must be an integer between 1 and %d.', self::MAX_LIMIT),
                ),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        // Имя сортировки уходит в SQL подстановкой, поэтому оно
        // становится типом здесь или не становится вовсе. Неизвестное
        // значение — отказ, а не молчаливый откат на умолчание: тем же
        // правилом, что и превышение limit.
        $sort = UnitEconomicsSort::tryFrom(
            $request->query->getString('sort', UnitEconomicsSort::Revenue->value),
        );
        if (null === $sort) {
            return self::invalid('invalid_sort', \sprintf(
                'sort must be one of: %s.',
                implode(', ', array_column(UnitEconomicsSort::cases(), 'value')),
            ));
        }

        $direction = UnitEconomicsDirection::tryFrom(
            $request->query->getString('direction', UnitEconomicsDirection::Desc->value),
        );
        if (null === $direction) {
            return self::invalid('invalid_direction', 'direction must be one of: asc, desc.');
        }

        $cursor = null;
        if ($request->query->has('cursor')) {
            $raw = (string) $request->query->get('cursor');
            $cursor = UnitEconomicsCursor::fromString($raw);
            if (null === $cursor) {
                return self::invalid('invalid_cursor', 'cursor is malformed.');
            }

            // Курсор снят в каком-то представлении, и в другом означает
            // другую точку — и по порядку, и по окну: значения сортировки
            // посчитаны за период. Применить его к иному значило бы отдать
            // страницу, которая выглядит правдоподобно и при этом неверна.
            if (!$cursor->matches($sort, $direction, $days)) {
                return self::invalid('invalid_cursor', 'cursor was issued for a different sort order or window.');
            }
        }

        $report = ($this->buildReport)($companyId, $from, $to, $limit, $days, $sort, $direction, $cursor);

        return new JsonResponse(new UnitEconomicsResponse(
            from: $from->format('Y-m-d'),
            to: $to->format('Y-m-d'),
            currency: $report->currency,
            skus: array_map(
                static fn (UnitEconomicsSku $sku): UnitEconomicsSkuResponse => new UnitEconomicsSkuResponse(
                    marketplaceSku: $sku->marketplaceSku,
                    name: $sku->name,
                    offerId: $sku->offerId,
                    photoUrl: $sku->photoUrl,
                    deliveredQuantity: $sku->deliveredQuantity,
                    orderedQuantity: $sku->orderedQuantity,
                    revenueMinor: $sku->revenueMinor,
                    commissionMinor: $sku->commissionMinor,
                    expenses: array_map(self::expense(...), $sku->expenses),
                    expensesTotalMinor: $sku->expensesTotalMinor,
                    deductionsTotalMinor: $sku->deductionsTotalMinor,
                    marginMinor: $sku->marginMinor,
                    costTotalMinor: $sku->costTotalMinor,
                    quantityWithoutCost: $sku->quantityWithoutCost,
                    profitMinor: $sku->profitMinor,
                    costCorrectedAt: $sku->costCorrectedAt,
                ),
                $report->skus,
            ),
            cabinetExpenses: array_map(self::expense(...), $report->cabinetExpenses),
            cabinetExpensesTotalMinor: $report->cabinetExpensesTotalMinor,
            daysWithoutExpenses: $report->daysWithoutExpenses,
            nextCursor: $report->nextCursor,
        ));
    }

    /**
     * Отказ на разборе ввода. Один вид ответа на все параметры: три
     * копии одного JsonResponse разъезжаются, и разъезжаются молча.
     */
    private static function invalid(string $code, string $message): JsonResponse
    {
        return new JsonResponse(
            new ValidationErrorResponse(
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
                code: $code,
                message: $message,
            ),
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    private static function expense(UnitEconomicsExpense $expense): UnitEconomicsExpenseResponse
    {
        return new UnitEconomicsExpenseResponse(
            feeTypeId: $expense->feeTypeId,
            name: $expense->name,
            amountMinor: $expense->amountMinor,
        );
    }
}
