<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Ui\Controller;

use App\ProductResearchOzon\Application\StartResearchRunAction;
use App\ProductResearchOzon\Ui\Response\RunResponse;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/api/companies/{companyId}/product-research-ozon/projects/{projectId}/runs', requirements: ['companyId' => Requirement::UUID, 'projectId' => Requirement::UUID], methods: ['POST'])]
final class StartResearchRunController extends ResearchController
{
    public function __construct(bool $researchEnabled, private readonly StartResearchRunAction $action)
    {
        parent::__construct($researchEnabled);
    }

    #[OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['requestId', 'expectedVersion', 'previewCalendarDate'], properties: [new OA\Property(property: 'requestId', type: 'string', format: 'uuid'), new OA\Property(property: 'expectedVersion', type: 'integer', minimum: 1), new OA\Property(property: 'previewCalendarDate', type: 'string', format: 'date')], additionalProperties: false))]
    #[OA\Response(response: 202, description: 'Запуск сохранён; доставка и загрузка выполняются фоново', content: new Model(type: RunResponse::class))]
    #[OA\Response(response: 401, description: 'Нет seller-сессии', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 403, description: 'Нет доступа к компании', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 404, description: 'Модуль выключен или проект отсутствует', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 409, description: 'Устаревшая версия/предпросмотр, конфликт requestId или активный запуск', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 422, description: 'Неверный запрос или все месяцы загружены', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 503, description: 'Источник не настроен', content: new Model(type: ValidationErrorResponse::class))]
    public function __invoke(string $companyId, string $projectId, Request $request): JsonResponse
    {
        return $this->respond($request, function () use ($companyId, $projectId, $request): JsonResponse {
            $body = $request->getContent();
            if (\strlen($body) > 1024) {
                throw new \InvalidArgumentException('invalid_request');
            }
            try {
                $data = json_decode($body, false, 4, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new \InvalidArgumentException('invalid_request');
            }
            if (!$data instanceof \stdClass || 3 !== \count(get_object_vars($data)) || [] !== array_diff(array_keys(get_object_vars($data)), ['requestId', 'expectedVersion', 'previewCalendarDate']) || !\is_string($data->requestId) || !\is_int($data->expectedVersion) || !\is_string($data->previewCalendarDate)) {
                throw new \InvalidArgumentException('invalid_request');
            }

            return new JsonResponse(new RunResponse(($this->action)($companyId, $projectId, $data->requestId, $data->expectedVersion, $data->previewCalendarDate, $this->actor($request))), 202);
        });
    }
}
