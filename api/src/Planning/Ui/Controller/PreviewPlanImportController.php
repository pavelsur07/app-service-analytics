<?php

declare(strict_types=1);

namespace App\Planning\Ui\Controller;

use App\Planning\Application\PlanImportPreviewOutcome;
use App\Planning\Application\PreviewPlanImportAction;
use App\Planning\Ui\Response\PlanImportPreviewResponse;
use App\Shared\Ui\RequestAttributes;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/api/companies/{companyId}/planning/accounts/{accountId}/imports/preview', name: 'planning_import_preview', requirements: ['companyId' => Requirement::UUID, 'accountId' => Requirement::UUID], methods: ['POST'])]
final readonly class PreviewPlanImportController
{
    public function __construct(private PreviewPlanImportAction $preview) {}

    #[OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(required: ['file'], properties: [new OA\Property(property: 'file', type: 'string', format: 'binary')])))]
    #[OA\Response(response: 200, description: 'Файл проверен', content: new Model(type: PlanImportPreviewResponse::class))]
    #[OA\Response(response: 403, description: 'Нет доступа к компании', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 404, description: 'Кабинет не найден', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 422, description: 'Файл или строки некорректны', content: new Model(type: PlanImportPreviewResponse::class))]
    public function __invoke(string $companyId, string $accountId, Request $request): JsonResponse
    {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid() || 'xlsx' !== strtolower($file->getClientOriginalExtension())) {
            return new JsonResponse(new ValidationErrorResponse(422, 'xlsx_file_required', 'Загрузите корректный файл .xlsx.'), 422);
        }
        $actorId = $request->attributes->get(RequestAttributes::ActorUserId);
        \assert(\is_string($actorId));
        $result = ($this->preview)($companyId, $accountId, $actorId, $file->getPathname());

        return match ($result->outcome) {
            PlanImportPreviewOutcome::Ready => new JsonResponse(PlanImportPreviewResponse::ready($result->preview ?? throw new \LogicException('Preview не создан.'))),
            PlanImportPreviewOutcome::Invalid => new JsonResponse(PlanImportPreviewResponse::invalid($result->issues), 422),
            PlanImportPreviewOutcome::AccountNotFound => new JsonResponse(new ValidationErrorResponse(404, 'marketplace_account_not_found', 'Кабинет маркетплейса не найден.'), 404),
        };
    }
}
