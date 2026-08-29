<?php

declare(strict_types=1);

namespace App\Identity\Ui\Controller;

use App\Identity\Application\ChangeCompanyStatusAction;
use App\Identity\Domain\Administrator;
use App\Identity\Domain\ValueObject\CompanyStatus;
use App\Identity\Ui\Response\ClientAccountStatusResponse;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Блокировка и включение клиентского аккаунта (ADR-017). Обе роли
 * контура это могут — ROLE_ADMIN, не верхняя роль.
 *
 * **Параметр маршрута назван `id`, а не `companyId`, намеренно.**
 * `CompanyAccessSubscriber` срабатывает по имени `companyId` в атрибутах
 * запроса и начал бы искать членство администратора в этой компании —
 * которого у него нет и быть не может. Guard в подписчике этот случай
 * закрывает, но имя всё равно не совпадает: две защиты дешевле одной,
 * а совпадение имён здесь ничего не даёт.
 *
 * Один маршрут на оба перехода, а не два: целевое состояние —
 * единственное, чем они различаются, и сценарий за ними уже один
 * (`ChangeCompanyStatusAction`).
 */
#[Route(
    '/api/admin/companies/{id}/status',
    name: 'identity_admin_company_status',
    requirements: ['id' => Requirement::UUID],
    methods: ['POST'],
)]
#[IsGranted('ROLE_ADMIN')]
final class SetClientAccountStatusController
{
    public function __construct(
        private readonly Security $security,
        private readonly ChangeCompanyStatusAction $changeStatus,
    ) {
    }

    #[OA\RequestBody(content: new OA\JsonContent(
        required: ['status'],
        properties: [
            new OA\Property(property: 'status', type: 'string', enum: ['active', 'blocked']),
        ],
    ))]
    #[OA\Response(
        response: 200,
        description: 'Целевое состояние достигнуто; changed=false, если аккаунт уже был в нём',
        content: new Model(type: ClientAccountStatusResponse::class),
    )]
    #[OA\Response(
        response: 422,
        description: 'Неизвестный статус',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        $status = $this->statusFrom($request);
        if (null === $status) {
            return new JsonResponse(
                new ValidationErrorResponse(Response::HTTP_UNPROCESSABLE_ENTITY, 'status_invalid', 'Статус — active или blocked.'),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $actor = $this->security->getUser();
        \assert($actor instanceof Administrator);

        // Несуществующая компания даёт changed=false тем же путём, что
        // и повтор: UPDATE не нашёл строки. Отдельного 404 нет —
        // прочитать компанию по одному лишь идентификатору, чтобы
        // отличить эти случаи, запрещено (CLAUDE.md §1), и админке
        // различие ничего не даёт: список она получает тем же запросом,
        // из которого и берёт идентификаторы.
        $changed = ($this->changeStatus)($id, $status, $actor);

        return new JsonResponse(new ClientAccountStatusResponse(status: $status->value, changed: $changed));
    }

    private function statusFrom(Request $request): ?CompanyStatus
    {
        try {
            $decoded = json_decode($request->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($decoded) || !\is_string($decoded['status'] ?? null)) {
            return null;
        }

        return CompanyStatus::tryFrom($decoded['status']);
    }
}
