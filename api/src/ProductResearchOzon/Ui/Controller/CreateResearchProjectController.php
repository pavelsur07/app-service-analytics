<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Ui\Controller;

use App\ProductResearchOzon\Application\CreateResearchProjectAction;
use App\ProductResearchOzon\Infrastructure\Query\ResearchProjectsQuery;
use App\ProductResearchOzon\Ui\Request\ResearchRequest;
use App\ProductResearchOzon\Ui\Response\ProjectResponse;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/api/companies/{companyId}/product-research-ozon/projects', requirements: ['companyId' => Requirement::UUID], methods: ['POST'])]
final class CreateResearchProjectController extends ResearchController
{
    public function __construct(bool $researchEnabled, private readonly CreateResearchProjectAction $action, private readonly ResearchProjectsQuery $projects)
    {
        parent::__construct($researchEnabled);
    }

    #[OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['categoryPath', 'previewCalendarDate'], properties: [new OA\Property(property: 'categoryPath', type: 'string', minLength: 1, maxLength: 2048), new OA\Property(property: 'periods', type: 'array', minItems: 1, maxItems: 24, items: new OA\Items(type: 'object', required: ['fromMonth', 'toMonth'], properties: [new OA\Property(property: 'fromMonth', type: 'string', pattern: '^20[0-9]{2}-(0[1-9]|1[0-2])$'), new OA\Property(property: 'toMonth', type: 'string', pattern: '^20[0-9]{2}-(0[1-9]|1[0-2])$'), new OA\Property(property: 'name', type: 'string', maxLength: 100)], additionalProperties: false)), new OA\Property(property: 'shortcut', oneOf: [new OA\Schema(type: 'object', required: ['kind', 'months'], properties: [new OA\Property(property: 'kind', type: 'string', enum: ['last_closed_months']), new OA\Property(property: 'months', type: 'integer', enum: [3, 6, 12])], additionalProperties: false), new OA\Schema(type: 'object', required: ['kind', 'period'], properties: [new OA\Property(property: 'kind', type: 'string', enum: ['previous_year']), new OA\Property(property: 'period', type: 'object', required: ['fromMonth', 'toMonth'], properties: [new OA\Property(property: 'fromMonth', type: 'string', pattern: '^20[0-9]{2}-(0[1-9]|1[0-2])$'), new OA\Property(property: 'toMonth', type: 'string', pattern: '^20[0-9]{2}-(0[1-9]|1[0-2])$'), new OA\Property(property: 'name', type: 'string', maxLength: 100)], additionalProperties: false)], additionalProperties: false)]), new OA\Property(property: 'includeCurrentMonth', type: 'boolean'), new OA\Property(property: 'name', type: 'string', maxLength: 200), new OA\Property(property: 'comment', type: 'string', maxLength: 2000), new OA\Property(property: 'previewCalendarDate', type: 'string', format: 'date')], additionalProperties: false, oneOf: [new OA\Schema(required: ['periods'], not: new OA\Schema(required: ['shortcut'])), new OA\Schema(required: ['shortcut'], not: new OA\Schema(required: ['periods']))]))]
    #[OA\Response(response: 201, description: 'Черновик проекта', content: new Model(type: ProjectResponse::class))]
    #[OA\Response(response: 401, description: 'Нет seller-сессии', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 403, description: 'Нет доступа к компании', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 404, description: 'Модуль выключен или проект отсутствует', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 409, description: 'Версия или дата предпросмотра устарели', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 422, description: 'Неверные поля/периоды/категория', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 503, description: 'Справочник ещё не готов', content: new Model(type: ValidationErrorResponse::class))]
    public function __invoke(string $companyId, Request $request): JsonResponse
    {
        return $this->respond($request, function () use ($companyId, $request): JsonResponse {
            $result = ($this->action)($companyId, ResearchRequest::create($request->getContent()), $this->actor($request));

            return new JsonResponse(ProjectResponse::mutation($result, ResearchProjectsQuery::mapStatus($this->projects->state($companyId, $result->project->id()->toRfc4122())->executeQuery()->fetchOne())), 201);
        });
    }
}
