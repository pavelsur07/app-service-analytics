<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Controller;

use App\Ingestion\Application\ConnectAdvertisingResult;
use App\Ingestion\Application\ConnectOzonAdvertisingAction;
use App\Ingestion\Ui\Request\ReplaceAdvertisingCredentialsRequest;
use App\Ingestion\Ui\Response\Connections\ConnectedAdvertisingResponse;
use App\Shared\Ui\RequestAttributes;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Ввод или замена рекламного ключа подключения (ADR-026, п. 1).
 *
 * Тот же контракт, что у замены ключа Seller API: ключ проверяется
 * у площадки до сохранения, 422 — площадка не приняла ключ (или ключ
 * от другого кабинета), 503 — площадка не ответила.
 *
 * companyId первым сегментом (§1); 403 для чужой компании отдаёт
 * CompanyAccessSubscriber, до контроллера запрос не доходит.
 */
#[Route(
    '/api/companies/{companyId}/connections/{marketplaceAccountId}/advertising-credentials',
    name: 'ingestion_connection_advertising_credentials_replace',
    requirements: ['companyId' => Requirement::UUID, 'marketplaceAccountId' => Requirement::UUID],
    methods: ['PUT'],
)]
final class ReplaceAdvertisingCredentialsController
{
    public function __construct(
        private readonly ConnectOzonAdvertisingAction $connectAdvertising,
    ) {
    }

    #[OA\RequestBody(content: new OA\JsonContent(
        required: ['clientId', 'clientSecret', 'version'],
        properties: [
            new OA\Property(property: 'clientId', type: 'string', description: 'client_id сервисного аккаунта Performance API'),
            new OA\Property(property: 'clientSecret', type: 'string'),
            new OA\Property(property: 'version', type: 'integer', description: 'Версия подключения из ответа списка (ADR-008)'),
        ],
    ))]
    #[OA\Response(
        response: 200,
        description: 'Рекламный ключ принят площадкой и сохранён',
        content: new Model(type: ConnectedAdvertisingResponse::class),
    )]
    #[OA\Response(
        response: 404,
        description: 'У этой компании нет такого подключения',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    #[OA\Response(
        response: 422,
        description: 'Площадка не приняла ключ (advertising_credentials_rejected), ключ от другого кабинета (advertising_credentials_of_another_cabinet), подключение отозвано либо тело запроса неполное',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    #[OA\Response(
        response: 409,
        description: 'Подключение изменил кто-то ещё — перечитать и повторить (ADR-008)',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    #[OA\Response(
        response: 503,
        description: 'Площадка не ответила — повторить позже, ключ выпускать не нужно',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    #[OA\Response(
        response: 403,
        description: 'Пользователь не состоит в этой компании',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    public function __invoke(string $companyId, string $marketplaceAccountId, Request $request): JsonResponse
    {
        try {
            $credentials = ReplaceAdvertisingCredentialsRequest::fromJson($request->getContent());
        } catch (\InvalidArgumentException $invalid) {
            return $this->error(Response::HTTP_UNPROCESSABLE_ENTITY, $invalid->getMessage(), 'Укажите client_id и client_secret рекламного ключа.');
        }

        $actorUserId = $request->attributes->get(RequestAttributes::ActorUserId);
        \assert(\is_string($actorUserId));

        $result = ($this->connectAdvertising)(
            $companyId,
            $marketplaceAccountId,
            $credentials->clientId,
            $credentials->clientSecret,
            $credentials->version,
            $actorUserId,
        );

        return match ($result) {
            ConnectAdvertisingResult::Connected => new JsonResponse(
                new ConnectedAdvertisingResponse(id: $marketplaceAccountId, advertisingState: 'active'),
            ),
            ConnectAdvertisingResult::Rejected => $this->error(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'advertising_credentials_rejected',
                'Ozon не принял этот рекламный ключ. Проверьте client_id и client_secret в кабинете продавца: Настройки → API-ключи → Performance API.',
            ),
            ConnectAdvertisingResult::WrongCabinet => $this->error(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'advertising_credentials_of_another_cabinet',
                'Товаров из рекламных кампаний этого ключа нет в каталоге подключённого магазина. Проверьте, что ключ выпущен в том же кабинете.',
            ),
            ConnectAdvertisingResult::Unavailable => $this->error(
                Response::HTTP_SERVICE_UNAVAILABLE,
                'marketplace_unavailable',
                'Ozon сейчас не отвечает. Ключ выпускать не нужно — повторите через несколько минут.',
            ),
            ConnectAdvertisingResult::Revoked => $this->error(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'connection_revoked',
                'Подключение отключено. Рекламный ключ к нему не добавить — напишите нам.',
            ),
            ConnectAdvertisingResult::VersionConflict => $this->error(
                Response::HTTP_CONFLICT,
                'version_conflict',
                'Подключение изменил кто-то ещё. Обновите страницу и повторите.',
            ),
            ConnectAdvertisingResult::NotFound => $this->error(
                Response::HTTP_NOT_FOUND,
                'connection_not_found',
                'Подключение не найдено.',
            ),
        };
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(new ValidationErrorResponse($status, $code, $message), $status);
    }
}
