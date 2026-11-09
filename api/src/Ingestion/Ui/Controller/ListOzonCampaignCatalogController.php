<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Controller;

use App\Ingestion\Domain\OzonCampaignCatalogItem;
use App\Ingestion\Infrastructure\Query\OzonCampaignCatalogQuery;
use App\Ingestion\Ui\Response\OzonCampaignCatalogResponse;
use App\Shared\Ui\QueryParameter;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

#[Route('/api/companies/{companyId}/ozon-advertising/campaigns', name: 'ingestion_ozon_campaign_catalog', requirements: ['companyId' => Requirement::UUID], methods: ['GET'])]
final readonly class ListOzonCampaignCatalogController
{
    public function __construct(private OzonCampaignCatalogQuery $catalog)
    {
    }

    #[OA\Parameter(name: 'accountId', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 200, default: 50))]
    #[OA\Parameter(name: 'cursor', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'includeArchived', in: 'query', schema: new OA\Schema(type: 'string', enum: ['true', 'false'], default: 'false'))]
    #[OA\Response(response: 200, description: 'Сохранённый каталог кампаний; открытие страницы не вызывает Ozon', content: new Model(type: OzonCampaignCatalogResponse::class))]
    #[OA\Response(response: 403, description: 'Нет доступа к компании', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 409, description: 'Снимок изменился; начать просмотр заново', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 422, description: 'Неверные параметры', content: new Model(type: ValidationErrorResponse::class))]
    public function __invoke(string $companyId, Request $request): JsonResponse
    {
        $account = $request->query->get('accountId');
        $limit = QueryParameter::int($request, 'limit', 50);
        $archived = $request->query->get('includeArchived', 'false');
        $cursor = $request->query->get('cursor');
        if (!\is_string($account) || !Uuid::isValid($account) || null === $limit || $limit < 1 || $limit > 200 || !\in_array($archived, ['true', 'false'], true)) {
            return self::invalid();
        }
        $rawId = null;
        $after = null;
        if (null !== $cursor) {
            if (!\is_string($cursor) || \strlen($cursor) > 64) {
                return self::invalid();
            }
            $parts = explode(':', $cursor);
            if (3 !== \count($parts) || !Uuid::isValid($parts[0]) || $parts[1] !== ('true' === $archived ? '1' : '0') || 1 !== preg_match('/\A[1-9][0-9]{0,19}\z/', $parts[2])) {
                return self::invalid();
            }
            $rawId = Uuid::fromString($parts[0])->toRfc4122();
            $after = $parts[2];
        }
        $row = $this->catalog->build($companyId, $account, $after, $limit, 'true' === $archived)->executeQuery()->fetchAssociative();
        if (null !== $rawId && (false === $row || $row['raw_document_id'] !== $rawId)) {
            return new JsonResponse(new ValidationErrorResponse(409, 'catalog_changed', 'Список кампаний обновился. Начните просмотр заново.'), 409);
        }
        if (false === $row) {
            return new JsonResponse(new OzonCampaignCatalogResponse([], 'not_loaded', null, 0, null, null, false));
        }
        $itemsJson = $row['items'];
        $receivedAt = $row['received_at'];
        $sourceId = $row['raw_document_id'];
        if (!\is_string($itemsJson) || !\is_string($receivedAt) || !\is_string($sourceId)) {
            throw new \UnexpectedValueException('Invalid catalog snapshot row.');
        }
        $decoded = json_decode($itemsJson, true, 64, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded) || !array_is_list($decoded)) {
            throw new \UnexpectedValueException('Invalid catalog page.');
        }
        $items = [];
        foreach ($decoded as $item) {
            if (!\is_array($item) || array_is_list($item)) {
                throw new \UnexpectedValueException('Invalid catalog page item.');
            }
            $items[] = OzonCampaignCatalogItem::fromRaw($item);
        }
        $hasNext = \count($items) > $limit;
        $items = \array_slice($items, 0, $limit);
        $last = [] === $items ? null : $items[\count($items) - 1];
        $next = $hasNext && null !== $last ? $sourceId.':'.('true' === $archived ? '1' : '0').':'.$last->id : null;
        $complete = true === $row['complete'] || 't' === $row['complete'] || 1 === $row['complete'];

        return new JsonResponse(new OzonCampaignCatalogResponse($items, $complete ? 'ready' : 'partial', (new \DateTimeImmutable($receivedAt))->format(\DATE_ATOM), self::integer($row['saved_count']), null === $row['total'] ? null : self::integer($row['total']), $next, true === $row['restored'] || 't' === $row['restored'] || 1 === $row['restored']));
    }

    private static function invalid(): JsonResponse
    {
        return new JsonResponse(new ValidationErrorResponse(422, 'invalid_catalog_query', 'Проверьте параметры списка кампаний.'), 422);
    }

    private static function integer(mixed $value): int
    {
        if (!\is_int($value) && !\is_string($value)) {
            throw new \UnexpectedValueException('Invalid catalog count.');
        }

        return (int) $value;
    }
}
