<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Controller;

use App\SalesPlanning\Application\ReadSalesPlanPerformanceAction;
use App\SalesPlanning\Application\SalesPlanPerformanceReadException;
use App\SalesPlanning\Application\SalesPlanPerformanceRequest;
use App\SalesPlanning\Infrastructure\Query\SalesPlanGrouping;
use App\SalesPlanning\Ui\Response\SalesPlanPerformanceResponse;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/api/companies/{companyId}/sales-plans/{planId}/performance', name: 'sales_planning_plan_performance', requirements: ['companyId' => Requirement::UUID, 'planId' => Requirement::UUID], methods: ['GET'])]
final readonly class ShowSalesPlanPerformanceController
{
    public function __construct(private ReadSalesPlanPerformanceAction $read)
    {
    }

    #[OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Parameter(name: 'groupBy', in: 'query', schema: new OA\Schema(type: 'string', default: 'day', enum: ['day', 'week', 'month', 'period']))]
    #[OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 20, minimum: 1, maximum: 50))]
    #[OA\Parameter(name: 'cursor', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 2048))]
    #[OA\Response(response: 200, description: 'Исходная числовая цель и текущие когортные показатели страницы SKU', content: new Model(type: SalesPlanPerformanceResponse::class))]
    #[OA\Response(response: 403, description: 'Нет членства в компании', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 404, description: 'План не найден у компании', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 409, description: 'Нет утверждённой базы, закрытый план либо неподдерживаемый внешний snapshot', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 422, description: 'Неверные параметры или не выбрана метрика', content: new Model(type: ValidationErrorResponse::class))]
    public function __invoke(string $companyId, string $planId, Request $request): JsonResponse
    {
        try {
            $query = $request->query->all();
            if ([] !== array_diff(array_keys($query), ['from', 'to', 'groupBy', 'limit', 'cursor'])) {
                throw new \InvalidArgumentException('Unsupported performance parameter.');
            }
            $from = self::date($query['from'] ?? null);
            $to = self::date($query['to'] ?? null);
            $rawGroup = $query['groupBy'] ?? 'day';
            $group = \is_string($rawGroup) ? SalesPlanGrouping::tryFrom($rawGroup) : null;
            $rawLimit = $query['limit'] ?? '20';
            $cursor = $query['cursor'] ?? null;
            if (null === $group || !\is_string($rawLimit) || 1 !== preg_match('/^[1-9]\d?$/D', $rawLimit) || (null !== $cursor && !\is_string($cursor))) {
                throw new \InvalidArgumentException('Invalid performance grouping, limit or cursor.');
            }
            $input = new SalesPlanPerformanceRequest($from, $to, $group, $cursor, (int) $rawLimit);
            $view = ($this->read)($companyId, $planId, $input);

            return new JsonResponse(SalesPlanPerformanceResponse::of($view));
        } catch (SalesPlanPerformanceReadException $error) {
            return new JsonResponse(new ValidationErrorResponse($error->httpStatus, $error->errorCode, $error->getMessage()), $error->httpStatus);
        } catch (\InvalidArgumentException $error) {
            return new JsonResponse(new ValidationErrorResponse(422, 'invalid_performance_request', $error->getMessage()), 422);
        }
    }

    private static function date(mixed $raw): \DateTimeImmutable
    {
        $date = \is_string($raw) && 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/D', $raw) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, new \DateTimeZone('UTC')) : false;
        if (false === $date || $date->format('Y-m-d') !== $raw) {
            throw new \InvalidArgumentException('A valid YYYY-MM-DD date is required.');
        }

        return $date;
    }
}
