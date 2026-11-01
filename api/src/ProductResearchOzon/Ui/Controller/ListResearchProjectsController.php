<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Ui\Controller;

use App\ProductResearchOzon\Infrastructure\Query\ResearchProjectRow;
use App\ProductResearchOzon\Infrastructure\Query\ResearchProjectsQuery;
use App\ProductResearchOzon\Ui\Request\ResearchRequest;
use App\ProductResearchOzon\Ui\Response\ProjectsResponse;
use App\ProductResearchOzon\Ui\Response\ProjectSummaryResponse;
use App\Shared\Ui\QueryParameter;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

#[Route('/api/companies/{companyId}/product-research-ozon/projects', requirements: ['companyId' => Requirement::UUID], methods: ['GET'])]
final class ListResearchProjectsController extends ResearchController
{
    public function __construct(bool $researchEnabled, private readonly ResearchProjectsQuery $query)
    {
        parent::__construct($researchEnabled);
    }

    #[OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 200, default: 50))]
    #[OA\Parameter(name: 'cursor', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(response: 200, description: 'Страница проектов', content: new Model(type: ProjectsResponse::class))]
    #[OA\Response(response: 401, description: 'Нет seller-сессии', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 403, description: 'Нет доступа к компании', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 404, description: 'Модуль выключен или проект отсутствует', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 409, description: 'Версия или дата предпросмотра устарели', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 422, description: 'Неверные поля/периоды/категория', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 503, description: 'Справочник ещё не готов', content: new Model(type: ValidationErrorResponse::class))]
    public function __invoke(string $companyId, Request $request): JsonResponse
    {
        return $this->respond($request, function () use ($companyId, $request): JsonResponse {
            ResearchRequest::keys($request->query->all(), ['limit', 'cursor']);
            $limit = QueryParameter::int($request, 'limit', ResearchProjectsQuery::DefaultLimit);
            if (null === $limit || $limit < 1 || $limit > ResearchProjectsQuery::MaxLimit) {
                throw new \InvalidArgumentException('invalid_limit');
            }
            $cursor = $request->query->all()['cursor'] ?? null;
            if (null !== $cursor && (!\is_string($cursor) || !Uuid::isValid($cursor))) {
                throw new \InvalidArgumentException('invalid_cursor');
            }
            $rows = $this->query->page($companyId, $cursor, $limit);
            $more = \count($rows) > $limit;
            $rows = \array_slice($rows, 0, $limit);
            $next = $more && [] !== $rows ? $rows[\count($rows) - 1]->id : null;

            return new JsonResponse(new ProjectsResponse(array_map(static fn (ResearchProjectRow $row): ProjectSummaryResponse => new ProjectSummaryResponse($row), $rows), $next));
        });
    }
}
