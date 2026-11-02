<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Ui\Controller;

use App\ProductResearchOzon\Domain\ResearchProblem;
use App\ProductResearchOzon\Infrastructure\Query\ProjectProgressQuery;
use App\ProductResearchOzon\Ui\Response\ProjectProgressResponse;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/api/companies/{companyId}/product-research-ozon/projects/{projectId}/progress', requirements: ['companyId' => Requirement::UUID, 'projectId' => Requirement::UUID], methods: ['GET'])]
final class GetResearchProjectProgressController extends ResearchController
{
    public function __construct(bool $researchEnabled, private readonly ProjectProgressQuery $query)
    {
        parent::__construct($researchEnabled);
    }

    #[OA\Response(response: 200, description: 'Один согласованный локальный срез всех зафиксированных требований проекта', content: new Model(type: ProjectProgressResponse::class))]
    #[OA\Response(response: 401, description: 'Нет seller-сессии', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 403, description: 'Нет доступа к компании', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 404, description: 'Модуль выключен или проект отсутствует', content: new Model(type: ValidationErrorResponse::class))]
    public function __invoke(string $companyId, string $projectId, Request $request): JsonResponse
    {
        return $this->respond($request, function () use ($companyId, $projectId): JsonResponse {
            $view = ProjectProgressQuery::map($this->query->forProject($companyId, $projectId)->executeQuery()->fetchAllAssociative()) ?? throw new ResearchProblem('project_not_found');

            return new JsonResponse(new ProjectProgressResponse($view), headers: ['Cache-Control' => 'no-store']);
        });
    }
}
