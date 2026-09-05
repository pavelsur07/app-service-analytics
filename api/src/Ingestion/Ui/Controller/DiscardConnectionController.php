<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Controller;

use App\Ingestion\Application\DiscardConnectionResult;
use App\Ingestion\Application\DiscardUnusedConnectionAction;
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
 * Удаление подключения, которое ничего не загрузило.
 *
 * Клиент может подключить не тот кабинет: номер настоящий, но другого
 * магазина, и исправить это нечем (external_shop_id неизменяем, замена
 * ключа на другой кабинет отвергается). Подключение без единой загруженной
 * строки — ошибка, а не актив: строка удаляется целиком, а не отзывается.
 *
 * 204, а не 200: удаление не отдаёт представление ресурса — тот же выбор,
 * что у RevokeExtensionTokenController. Повторный вызов идемпотентен
 * и отвечает 404 — строки уже нет, и это не отличается от «никогда
 * не было».
 *
 * companyId первым сегментом (§1); 403 для чужой компании отдаёт
 * CompanyAccessSubscriber, до контроллера запрос не доходит.
 */
#[Route(
    '/api/companies/{companyId}/connections/{marketplaceAccountId}',
    name: 'ingestion_connection_discard',
    requirements: ['companyId' => Requirement::UUID, 'marketplaceAccountId' => Requirement::UUID],
    methods: ['DELETE'],
)]
final class DiscardConnectionController
{
    public function __construct(
        private readonly DiscardUnusedConnectionAction $discard,
    ) {
    }

    #[OA\Response(response: 204, description: 'Подключение удалено. Повторное удаление идемпотентно.')]
    #[OA\Response(
        response: 404,
        description: 'У этой компании нет такого подключения',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    #[OA\Response(
        response: 409,
        description: 'У подключения есть загруженные документы — удалить нельзя, только заменить ключ',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    #[OA\Response(
        response: 403,
        description: 'Пользователь не состоит в этой компании',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    public function __invoke(string $companyId, string $marketplaceAccountId, Request $request): JsonResponse
    {
        // Автора ставит CompanyAccessSubscriber там же, где проверяет
        // членство: сюда запрос без него не доходит.
        $actorUserId = $request->attributes->get(RequestAttributes::ActorUserId);
        \assert(\is_string($actorUserId));

        $result = ($this->discard)($companyId, $marketplaceAccountId, $actorUserId);

        return match ($result) {
            DiscardConnectionResult::Discarded => new JsonResponse(null, Response::HTTP_NO_CONTENT),
            DiscardConnectionResult::NotFound => $this->error(
                Response::HTTP_NOT_FOUND,
                'connection_not_found',
                'Подключение не найдено.',
            ),
            DiscardConnectionResult::HasHistory => $this->error(
                Response::HTTP_CONFLICT,
                'connection_has_history',
                'У подключения уже есть загруженные данные — удалить его нельзя. Если ключ выпущен не от того кабинета, замените его в этом же подключении.',
            ),
        };
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(new ValidationErrorResponse($status, $code, $message), $status);
    }
}
