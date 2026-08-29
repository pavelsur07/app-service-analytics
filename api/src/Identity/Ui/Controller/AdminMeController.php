<?php

declare(strict_types=1);

namespace App\Identity\Ui\Controller;

use App\Identity\Domain\Administrator;
use App\Identity\Ui\Response\AdminMeResponse;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Живость сессии администратора и его роль — одна точка правды,
 * как /api/auth/me у продавца: админка спрашивает её при загрузке,
 * чтобы решить, показывать ли раздел управления администраторами.
 *
 * Роль отсюда — подсказка интерфейсу, а не проверка права: настоящую
 * проверку делают access_control и #[IsGranted] на самих маршрутах.
 * Спрятанная кнопка защитой не является.
 */
#[Route('/api/admin/auth/me', name: 'identity_admin_auth_me', methods: ['GET'])]
#[OA\Response(
    response: 200,
    description: 'Текущий администратор',
    content: new Model(type: AdminMeResponse::class),
)]
#[OA\Response(
    response: 401,
    description: 'Сессия администратора отсутствует или истекла',
    content: new Model(type: ValidationErrorResponse::class),
)]
final class AdminMeController
{
    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $administrator = $this->security->getUser();
        // access_control не пускает сюда никого, кроме ROLE_ADMIN,
        // а эту роль отдаёт только Administrator.
        \assert($administrator instanceof Administrator);

        return new JsonResponse(new AdminMeResponse(
            email: $administrator->email(),
            role: $administrator->role()->value,
        ));
    }
}
