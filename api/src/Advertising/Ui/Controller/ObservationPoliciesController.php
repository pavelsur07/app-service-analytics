<?php

declare(strict_types=1);

namespace App\Advertising\Ui\Controller;

use App\Advertising\Application\ObservationEventResponse;
use App\Advertising\Application\ObservationPolicyResponse;
use App\Advertising\Application\SaveObservationPolicyAction;
use App\Advertising\Infrastructure\Query\ObservationEventsQuery;
use App\Advertising\Infrastructure\Query\ObservationPoliciesQuery;
use App\Advertising\Ui\Request\PolicyRequest;
use App\Shared\Ui\QueryParameter;
use App\Shared\Ui\RequestAttributes;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

#[Route('/api/companies/{companyId}/ozon-ads', requirements: ['companyId' => Requirement::UUID])]
final readonly class ObservationPoliciesController
{
    public function __construct(private bool $adsEnabled, private SaveObservationPolicyAction $save, private ObservationPoliciesQuery $policies, private ObservationEventsQuery $events)
    {
    }

    #[Route('/availability', methods: ['GET'])]
    #[OA\Response(response: 200, description: 'Доступность интерфейса наблюдения', content: new OA\JsonContent(type: 'object', required: ['enabled'], properties: [new OA\Property(property: 'enabled', type: 'boolean')]))]
    public function availability(Request $request): JsonResponse
    {
        if ('' === $this->actor($request)) {
            return new JsonResponse(new ValidationErrorResponse(403, 'access_denied', 'Нет доступа.'), 403);
        }

        return new JsonResponse(['enabled' => $this->adsEnabled]);
    }

    #[Route('/events', methods: ['GET'])]
    #[OA\Parameter(name: 'cursor', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Parameter(name: 'accountId', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 50))]
    #[OA\Response(response: 200, description: 'История наблюдения, новые записи первыми', content: new OA\JsonContent(type: 'object', required: ['items', 'nextCursor'], properties: [new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: new Model(type: ObservationEventResponse::class))), new OA\Property(property: 'nextCursor', type: 'string', format: 'uuid', nullable: true)]))]
    #[OA\Response(response: 404, description: 'Модуль выключен или политика не найдена', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 422, description: 'Неверные параметры или недоступное подключение', content: new Model(type: ValidationErrorResponse::class))]
    public function events(string $companyId, Request $request): JsonResponse
    {
        return $this->respond($request, function () use ($companyId, $request): JsonResponse {
            [$limit, $cursor] = $this->pagination($request);
            $items = array_map(ObservationEventsQuery::map(...), $this->events->list($companyId, $cursor, $limit, $this->accountFilter($request))->executeQuery()->fetchAllAssociative());

            return new JsonResponse(['items' => $items, 'nextCursor' => \count($items) === $limit ? ($items[\count($items) - 1] ?? null)?->id : null]);
        });
    }

    #[Route('/policies', methods: ['GET'])]
    #[OA\Parameter(name: 'accountId', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Parameter(name: 'enabled', in: 'query', schema: new OA\Schema(type: 'string', enum: ['true', 'false']))]
    #[OA\Parameter(name: 'cursor', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 50))]
    #[OA\Response(response: 200, description: 'Политики наблюдения, без автоматического управления рекламой', content: new OA\JsonContent(type: 'object', required: ['items', 'nextCursor'], properties: [new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: new Model(type: ObservationPolicyResponse::class))), new OA\Property(property: 'nextCursor', type: 'string', format: 'uuid', nullable: true)]))]
    #[OA\Response(response: 404, description: 'Модуль выключен или политика не найдена', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 422, description: 'Неверные параметры или недоступное подключение', content: new Model(type: ValidationErrorResponse::class))]
    public function list(string $companyId, Request $request): JsonResponse
    {
        return $this->respond($request, function () use ($companyId, $request): JsonResponse {
            [$limit, $cursor] = $this->pagination($request);
            $enabled = $request->query->get('enabled');
            if (null !== $enabled && !\in_array($enabled, ['true', 'false'], true)) {
                throw new \InvalidArgumentException('invalid_enabled');
            }
            $items = array_map(ObservationPoliciesQuery::map(...), $this->policies->list($companyId, $cursor, $limit, $this->accountFilter($request), null === $enabled ? null : 'true' === $enabled)->executeQuery()->fetchAllAssociative());

            return new JsonResponse(['items' => $items, 'nextCursor' => \count($items) === $limit ? ($items[\count($items) - 1] ?? null)?->id : null]);
        });
    }

    #[Route('/policies', methods: ['POST'])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['accountId', 'campaignId', 'dailyLimit', 'start', 'stop', 'pollSeconds', 'enabled'], properties: [new OA\Property(property: 'accountId', type: 'string', format: 'uuid'), new OA\Property(property: 'campaignId', type: 'string', pattern: '^[1-9][0-9]{0,18}$'), new OA\Property(property: 'dailyLimit', type: 'object', required: ['amountMinor', 'currency'], properties: [new OA\Property(property: 'amountMinor', type: 'integer', minimum: 1, maximum: 9007199254740991), new OA\Property(property: 'currency', type: 'string', enum: ['RUB'])], additionalProperties: false), new OA\Property(property: 'start', type: 'string', example: '09:00'), new OA\Property(property: 'stop', type: 'string', example: '21:00'), new OA\Property(property: 'pollSeconds', type: 'integer', minimum: 600, maximum: 86400), new OA\Property(property: 'enabled', type: 'boolean')], additionalProperties: false))]
    #[OA\Response(response: 201, description: 'Сохранено; применение сервисом подтверждается отдельно', content: new Model(type: ObservationPolicyResponse::class))]
    #[OA\Response(response: 404, description: 'Модуль выключен или политика не найдена', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 422, description: 'Неверные параметры или недоступное подключение', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 409, description: 'Конфликт версии или повтор кампании', content: new Model(type: ValidationErrorResponse::class))]
    public function create(string $companyId, Request $request): JsonResponse
    {
        return $this->respond($request, function () use ($companyId, $request): JsonResponse {
            $body = PolicyRequest::body($request->getContent());
            if (!\is_string($body['accountId'] ?? null) || !Uuid::isValid($body['accountId']) || !\is_string($body['campaignId'] ?? null)) {
                throw new \InvalidArgumentException('invalid_request');
            }
            $policy = $this->save->create($companyId, $body['accountId'], $body['campaignId'], PolicyRequest::input($body, true), $this->actor($request));

            return new JsonResponse($this->readPolicy($companyId, $policy->id()->toRfc4122()), 201);
        });
    }

    #[Route('/policies/{policyId}', requirements: ['policyId' => Requirement::UUID], methods: ['PUT'])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['version', 'dailyLimit', 'start', 'stop', 'pollSeconds', 'enabled'], properties: [new OA\Property(property: 'version', type: 'integer', minimum: 1), new OA\Property(property: 'dailyLimit', type: 'object', required: ['amountMinor', 'currency'], properties: [new OA\Property(property: 'amountMinor', type: 'integer', minimum: 1, maximum: 9007199254740991), new OA\Property(property: 'currency', type: 'string', enum: ['RUB'])], additionalProperties: false), new OA\Property(property: 'start', type: 'string'), new OA\Property(property: 'stop', type: 'string'), new OA\Property(property: 'pollSeconds', type: 'integer', minimum: 600, maximum: 86400), new OA\Property(property: 'enabled', type: 'boolean')], additionalProperties: false))]
    #[OA\Response(response: 200, description: 'Сохранена новая версия наблюдения', content: new Model(type: ObservationPolicyResponse::class))]
    #[OA\Response(response: 404, description: 'Модуль выключен или политика не найдена', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 422, description: 'Неверные параметры или недоступное подключение', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 409, description: 'Конфликт версии или повтор кампании', content: new Model(type: ValidationErrorResponse::class))]
    public function update(string $companyId, string $policyId, Request $request): JsonResponse
    {
        return $this->respond($request, function () use ($companyId, $policyId, $request): JsonResponse {
            $body = PolicyRequest::body($request->getContent());
            if (!\is_int($body['version'] ?? null) || $body['version'] < 1) {
                throw new \InvalidArgumentException('invalid_version');
            }
            $this->save->update($companyId, $policyId, $body['version'], PolicyRequest::input($body, false), $this->actor($request));

            return new JsonResponse($this->readPolicy($companyId, $policyId));
        });
    }

    /** @param callable(): JsonResponse $operation */
    private function respond(Request $request, callable $operation): JsonResponse
    {
        if (!$this->adsEnabled) {
            return new JsonResponse(new ValidationErrorResponse(404, 'feature_disabled', 'Модуль выключен.'), 404);
        }
        if ('' === $this->actor($request)) {
            return new JsonResponse(new ValidationErrorResponse(403, 'access_denied', 'Нет доступа.'), 403);
        }
        try {
            return $operation();
        } catch (\DomainException $problem) {
            $status = match ($problem->getMessage()) {
                'policy_not_found' => 404, 'version_conflict' => 409, default => 422,
            };

            return new JsonResponse(new ValidationErrorResponse($status, $problem->getMessage(), 'Проверьте подключение и версию политики.'), $status);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(new ValidationErrorResponse(422, 'invalid_request', 'Проверьте поля политики наблюдения.'), 422);
        }
    }

    /** @return array{int, ?string} */
    private function pagination(Request $request): array
    {
        $limit = QueryParameter::int($request, 'limit', 50);
        if (null === $limit || $limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('invalid_limit');
        }
        $cursor = $request->query->get('cursor');
        if (null !== $cursor && (!\is_string($cursor) || !Uuid::isValid($cursor))) {
            throw new \InvalidArgumentException('invalid_cursor');
        }

        return [$limit, $cursor];
    }

    private function accountFilter(Request $request): ?string
    {
        $account = $request->query->get('accountId');
        if (null !== $account && (!\is_string($account) || !Uuid::isValid($account))) {
            throw new \InvalidArgumentException('invalid_account');
        }

        return $account;
    }

    private function actor(Request $request): string
    {
        $actor = $request->attributes->get(RequestAttributes::ActorUserId);

        return \is_string($actor) ? $actor : '';
    }

    private function readPolicy(string $companyId, string $policyId): ObservationPolicyResponse
    {
        $row = $this->policies->one($companyId, $policyId)->executeQuery()->fetchAssociative();
        if (false === $row) {
            throw new \DomainException('policy_not_found');
        }

        return ObservationPoliciesQuery::map($row);
    }
}
