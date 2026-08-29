<?php

declare(strict_types=1);

namespace App\Identity\Ui\Controller;

use App\Identity\Ui\Response\AdminMeResponse;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Тот же приём, что у LoginController контура продавца: маршрут обязан
 * существовать, потому что роутинг проверяет его раньше, чем firewall
 * перехватит check_path, а сам метод недостижим — json_login отвечает
 * через AdminLoginSuccessHandler/LoginFailureHandler.
 */
#[Route('/api/admin/auth/login', name: 'identity_admin_auth_login', methods: ['POST'])]
#[OA\RequestBody(content: new OA\JsonContent(
    required: ['email', 'password'],
    properties: [
        new OA\Property(property: 'email', type: 'string'),
        new OA\Property(property: 'password', type: 'string'),
    ],
))]
#[OA\Response(
    response: 200,
    description: 'Вход администратора выполнен, сессия установлена',
    content: new Model(type: AdminMeResponse::class),
)]
#[OA\Response(
    response: 401,
    description: 'Неверный email или пароль — сообщение одинаково для обоих случаев (ADR-007)',
    content: new Model(type: ValidationErrorResponse::class),
)]
final class AdminLoginController
{
    public function __invoke(): never
    {
        throw new \LogicException('json_login перехватывает запрос раньше — этот метод недостижим.');
    }
}
