<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Ui\Controller;

use App\ProductResearchOzon\Application\GetResearchRunAction;
use App\ProductResearchOzon\Ui\Response\RunResponse;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/api/companies/{companyId}/product-research-ozon/projects/{projectId}/runs/{runId}', requirements: ['companyId' => Requirement::UUID, 'projectId' => Requirement::UUID, 'runId' => Requirement::UUID], methods: ['GET'])]
final class GetResearchRunController extends ResearchController
{
    public function __construct(bool $researchEnabled, private readonly GetResearchRunAction $action)
    {
        parent::__construct($researchEnabled);
    }

    #[OA\Response(response: 200, description: 'Локальная проекция прогресса запуска', content: new Model(type: RunResponse::class))]
    #[OA\Response(response: 401, description: 'Нет seller-сессии', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 403, description: 'Нет доступа к компании', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 404, description: 'Модуль выключен или запуск отсутствует', content: new Model(type: ValidationErrorResponse::class))]
    public function __invoke(string $companyId, string $projectId, string $runId, Request $request): JsonResponse
    {
        return $this->respond($request, fn (): JsonResponse => new JsonResponse(new RunResponse(($this->action)($companyId, $projectId, $runId))));
    }
}
