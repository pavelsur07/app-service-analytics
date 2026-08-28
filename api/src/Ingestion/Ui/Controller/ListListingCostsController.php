<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Controller;

use App\Ingestion\Application\ListListingCostsAction;
use App\Ingestion\Infrastructure\Query\ListingCostRow;
use App\Ingestion\Infrastructure\Query\ListingCostsCursor;
use App\Ingestion\Infrastructure\Query\ListingCostsQuery;
use App\Ingestion\Ui\Response\ListingCostItemResponse;
use App\Ingestion\Ui\Response\ListingCostListResponse;
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
 * Карточки компании с выручкой и действующей себестоимостью — данные
 * экрана ввода (ADR-013).
 *
 * Порядок по выручке задаёт запрос: у клиента шестьдесят карточек,
 * и он введёт цену у пяти-десяти, которые кормят. Список по алфавиту
 * заставлял бы искать их глазами.
 *
 * companyId первым сегментом (§1); 403 для чужой компании отдаёт
 * CompanyAccessSubscriber, до контроллера запрос не доходит.
 */
#[Route(
    '/api/companies/{companyId}/listing-costs',
    name: 'ingestion_listing_costs_list',
    requirements: ['companyId' => Requirement::UUID],
    methods: ['GET'],
)]
final class ListListingCostsController
{
    private const int DEFAULT_DAYS = 30;
    private const int MAX_DAYS = 366;
    private const int DEFAULT_LIMIT = ListingCostsQuery::DEFAULT_LIMIT;
    private const int MAX_LIMIT = ListingCostsQuery::MAX_LIMIT;
    private const string TIMEZONE = 'Europe/Moscow';

    public function __construct(
        private readonly ListListingCostsAction $listCosts,
    ) {
    }

    #[OA\Parameter(
        name: 'days',
        in: 'query',
        description: 'Окно в днях, по выручке за которое отсортирован список',
        required: false,
        schema: new OA\Schema(type: 'integer', default: self::DEFAULT_DAYS, maximum: self::MAX_DAYS, minimum: 1),
    )]
    #[OA\Parameter(
        name: 'limit',
        in: 'query',
        required: false,
        schema: new OA\Schema(type: 'integer', default: self::DEFAULT_LIMIT, maximum: self::MAX_LIMIT, minimum: 1),
    )]
    #[OA\Parameter(
        name: 'cursor',
        in: 'query',
        description: 'Курсор следующей страницы из предыдущего ответа',
        required: false,
        schema: new OA\Schema(type: 'string'),
    )]
    #[OA\Response(
        response: 200,
        description: 'Карточки с выручкой и действующей себестоимостью',
        content: new Model(type: ListingCostListResponse::class),
    )]
    #[OA\Response(
        response: 422,
        description: 'Некорректные days, limit или cursor',
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
            return $this->error('invalid_days', \sprintf('days must be an integer between 1 and %d.', self::MAX_DAYS));
        }

        $limit = QueryParameter::int($request, 'limit', self::DEFAULT_LIMIT);
        if (null === $limit || $limit < 1 || $limit > self::MAX_LIMIT) {
            // 422, а не тихая обрезка до максимума (§5): клиент,
            // попросивший тысячу строк, должен узнать, что получил
            // не тысячу.
            return $this->error('invalid_limit', \sprintf('limit must be an integer between 1 and %d.', self::MAX_LIMIT));
        }

        $cursor = null;
        $raw = $request->query->get('cursor');
        if (\is_string($raw) && '' !== $raw) {
            // fromString отдаёт null, а не исключение: битый курсор
            // без этой проверки молча отдавал бы первую страницу вместо
            // отказа — то есть выглядел бы как «дальше ничего нет».
            $cursor = ListingCostsCursor::fromString($raw);
            if (null === $cursor) {
                return $this->error('invalid_cursor', 'cursor is malformed.');
            }
        }

        // Бизнес-дата в часовом поясе площадки: окно считается
        // по календарю Ozon, иначе рядом с полуночью день уезжает.
        $to = (new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE)))->setTime(0, 0);
        $from = $to->modify(\sprintf('-%d day', $days - 1));

        // Действующая цена — на сегодня. Даты «на когда смотреть» в
        // параметрах пока нет: экран вводит цену, а не изучает историю.
        $page = ($this->listCosts)($companyId, $from, $to, $to, $limit, $cursor);

        return new JsonResponse(new ListingCostListResponse(
            from: $from->format('Y-m-d'),
            to: $to->format('Y-m-d'),
            on: $to->format('Y-m-d'),
            items: array_map(
                static fn (ListingCostRow $row): ListingCostItemResponse => new ListingCostItemResponse(
                    marketplaceSku: $row->marketplaceSku,
                    marketplaceAccountId: $row->marketplaceAccountId,
                    offerId: $row->offerId,
                    name: $row->name,
                    revenueMinor: $row->revenueMinor,
                    deliveredQuantity: $row->deliveredQuantity,
                    costId: $row->costId,
                    unitCostMinor: $row->unitCostMinor,
                    costCurrency: $row->costCurrency,
                    costEffectiveFrom: $row->costEffectiveFrom,
                    costVersion: $row->costVersion,
                    deliveredSinceCost: $row->deliveredSinceCost,
                ),
                $page->listings,
            ),
            listingCount: $page->listingCount,
            pricedCount: $page->pricedCount,
            nextCursor: $page->nextCursor,
        ));
    }

    private function error(string $code, string $message): JsonResponse
    {
        return new JsonResponse(
            new ValidationErrorResponse(Response::HTTP_UNPROCESSABLE_ENTITY, $code, $message),
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
