<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Ui\Controller;

use App\ProductResearchOzon\Application\SearchResearchCategoriesAction;
use App\ProductResearchOzon\Ui\Request\ResearchRequest;
use App\ProductResearchOzon\Ui\Response\ResearchCategoriesResponse;
use App\Shared\Ui\QueryParameter;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/api/companies/{companyId}/product-research-ozon/categories', requirements: ['companyId' => Requirement::UUID], methods: ['GET'])]
final class SearchResearchCategoriesController extends ResearchController
{
    public function __construct(bool $researchEnabled, private readonly SearchResearchCategoriesAction $search)
    {
        parent::__construct($researchEnabled);
    }

    #[OA\Parameter(name: 'q', in: 'query', required: true, description: 'Не менее трёх букв, поиск по полному пути; % и _ буквальны', schema: new OA\Schema(type: 'string', minLength: 3, maxLength: 200))]
    #[OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 200, default: 50))]
    #[OA\Response(response: 200, description: 'Совпадения в полной свежей проекции', content: new Model(type: ResearchCategoriesResponse::class))]
    #[OA\Response(response: 401, description: 'Нужна seller-сессия', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 403, description: 'Нет доступа к компании', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 404, description: 'Модуль выключен', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 422, description: 'Неверные параметры поиска', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 503, description: 'Каталог ещё не получен или просрочен; дождитесь фонового обновления', content: new Model(type: ValidationErrorResponse::class))]
    public function __invoke(string $companyId, Request $request): JsonResponse
    {
        return $this->respond($request, function () use ($companyId, $request): JsonResponse {
            $values = $request->query->all();
            ResearchRequest::keys($values, ['q', 'limit']);
            $text = $values['q'] ?? null;
            $limit = QueryParameter::int($request, 'limit', 50);
            if (!\is_string($text) || str_contains($text, "\0") || mb_strlen($text) > 200 || preg_match_all('/\p{L}/u', $text) < 3 || null === $limit || $limit < 1 || $limit > 200) {
                throw new \InvalidArgumentException('invalid_request');
            }

            return new JsonResponse(new ResearchCategoriesResponse(($this->search)($companyId, trim($text), $limit)), headers: ['Cache-Control' => 'no-store']);
        });
    }
}
