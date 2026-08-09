<?php

declare(strict_types=1);

namespace App\Identity\Ui\Controller;

use App\Identity\Ui\Response\LoginResponse;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Маршрут обязателен — роутинг проверяет наличие маршрута раньше, чем
 * firewall перехватывает check_path (в отличие от logout, для которого
 * security.route_loader.logout регистрирует маршрут сам). Сам метод
 * никогда не выполняется: json_login перехватывает запрос до вызова
 * контроллера и отвечает через LoginSuccessHandler/LoginFailureHandler.
 * Атрибуты ниже — только для генерации OpenAPI-схемы (CLAUDE.md §10:
 * фронтенд импортирует типы ответов из неё, руками не описывает).
 */
#[Route('/api/auth/login', name: 'identity_auth_login', methods: ['POST'])]
#[OA\RequestBody(content: new OA\JsonContent(
    required: ['email', 'password'],
    properties: [
        new OA\Property(property: 'email', type: 'string'),
        new OA\Property(property: 'password', type: 'string'),
    ],
))]
#[OA\Response(
    response: 200,
    description: 'Вход выполнен, сессия установлена',
    content: new Model(type: LoginResponse::class),
)]
#[OA\Response(
    response: 401,
    description: 'Неверный email или пароль — сообщение одинаково для обоих случаев (ADR-007)',
    content: new Model(type: ValidationErrorResponse::class),
)]
final class LoginController
{
    public function __invoke(): never
    {
        throw new \LogicException('json_login перехватывает запрос раньше — этот метод недостижим.');
    }
}
