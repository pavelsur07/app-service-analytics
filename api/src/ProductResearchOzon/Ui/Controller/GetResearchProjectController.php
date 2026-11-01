<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Ui\Controller;

use App\ProductResearchOzon\Application\ResearchPreparation;
use App\ProductResearchOzon\Domain\CalendarSelection;
use App\ProductResearchOzon\Domain\ResearchProblem;
use App\ProductResearchOzon\Infrastructure\Query\ResearchProjectsQuery;
use App\ProductResearchOzon\Ui\Response\PreviewResponse;
use App\ProductResearchOzon\Ui\Response\ProjectResponse;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/api/companies/{companyId}/product-research-ozon/projects/{projectId}', requirements: ['companyId' => Requirement::UUID, 'projectId' => Requirement::UUID], methods: ['GET'])]
final class GetResearchProjectController extends ResearchController
{
    public function __construct(bool $researchEnabled, private readonly ResearchProjectsQuery $query, private readonly ResearchPreparation $prepare)
    {
        parent::__construct($researchEnabled);
    }

    #[OA\Response(response: 200, description: 'Проект и текущий предпросмотр', content: new Model(type: ProjectResponse::class))]
    #[OA\Response(response: 401, description: 'Нет seller-сессии', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 403, description: 'Нет доступа к компании', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 404, description: 'Модуль выключен или проект отсутствует', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 409, description: 'Версия или дата предпросмотра устарели', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 422, description: 'Неверные поля/периоды/категория', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 503, description: 'Справочник ещё не готов', content: new Model(type: ValidationErrorResponse::class))]
    public function __invoke(string $companyId, string $projectId, Request $request): JsonResponse
    {
        return $this->respond($request, function () use ($companyId, $projectId): JsonResponse {
            $view = $this->query->detail($companyId, $projectId) ?? throw new ResearchProblem('project_not_found');
            $preview = $this->prepare->preview(new CalendarSelection($view->periods), $view->project->includeCurrentMonth, $this->prepare->now());

            return new JsonResponse(new ProjectResponse($view->project, new PreviewResponse($preview, $view->project->categoryPath)));
        });
    }
}
