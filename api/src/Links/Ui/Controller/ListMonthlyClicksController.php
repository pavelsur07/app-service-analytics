<?php

declare(strict_types=1);

namespace App\Links\Ui\Controller;

use App\Links\Application\BuildMonthlyClicksAction;
use App\Links\Ui\Response\MonthlyClicksResponse;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(
    '/api/admin/links/{id}/clicks',
    name: 'links_admin_monthly_clicks',
    requirements: ['id' => Requirement::UUID],
    methods: ['GET'],
)]
#[IsGranted('ROLE_ADMIN')]
final readonly class ListMonthlyClicksController
{
    public function __construct(
        private BuildMonthlyClicksAction $buildClicks,
    ) {
    }

    #[OA\Parameter(name: 'month', in: 'query', required: true, schema: new OA\Schema(type: 'string', pattern: '^\\d{4}-(?:0[1-9]|1[0-2])$'))]
    #[OA\Response(response: 200, description: 'Переходы людей по дням выбранного месяца', content: new Model(type: MonthlyClicksResponse::class))]
    #[OA\Response(response: 404, description: 'Ссылка не найдена', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 422, description: 'Месяц некорректен или находится в будущем', content: new Model(type: ValidationErrorResponse::class))]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        $month = $request->query->all()['month'] ?? null;
        if (!\is_string($month)) {
            return $this->error(Response::HTTP_UNPROCESSABLE_ENTITY, 'month_invalid', 'Укажите месяц в формате YYYY-MM.');
        }

        try {
            $result = ($this->buildClicks)(
                $id,
                $month,
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            );
        } catch (\InvalidArgumentException $invalid) {
            $code = $invalid->getMessage();

            return $this->error(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $code,
                'month_in_future' === $code
                    ? 'Будущий месяц выбрать нельзя.'
                    : 'Укажите месяц в формате YYYY-MM.',
            );
        }

        if (null === $result) {
            return $this->error(Response::HTTP_NOT_FOUND, 'link_not_found', 'Короткая ссылка не найдена.');
        }

        return new JsonResponse(MonthlyClicksResponse::fromResult($result));
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(new ValidationErrorResponse($status, $code, $message), $status);
    }
}
