<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Ui\Controller;

use App\ProductResearchOzon\Ui\Response\ResearchAvailabilityResponse;
use App\Shared\Ui\RequestAttributes;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/api/companies/{companyId}/product-research-ozon/availability', requirements: ['companyId' => Requirement::UUID], methods: ['GET'])]
final readonly class GetResearchAvailabilityController
{
    public function __construct(private bool $researchEnabled)
    {
    }

    #[OA\Response(response: 200, description: 'Существующий серверный флаг; готовность каталога/загрузки проверяется отдельно', content: new Model(type: ResearchAvailabilityResponse::class))]
    #[OA\Response(response: 401, description: 'Нет seller-сессии', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 403, description: 'Нет доступа к компании', content: new Model(type: ValidationErrorResponse::class))]
    public function __invoke(Request $request): JsonResponse
    {
        if (!\is_string($request->attributes->get(RequestAttributes::ActorUserId))) {
            return new JsonResponse(new ValidationErrorResponse(403, 'access_denied', 'Нет доступа к компании.'), 403);
        }

        return new JsonResponse(new ResearchAvailabilityResponse($this->researchEnabled), headers: ['Cache-Control' => 'no-store']);
    }
}
