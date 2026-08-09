<?php

declare(strict_types=1);

namespace App\Identity\Ui\Controller;

use Symfony\Component\Routing\Attribute\Route;

/**
 * Маршрут обязателен — роутинг проверяет наличие маршрута раньше, чем
 * firewall перехватывает check_path (в отличие от logout, для которого
 * security.route_loader.logout регистрирует маршрут сам). Сам метод
 * никогда не выполняется: json_login перехватывает запрос до вызова
 * контроллера и отвечает через LoginSuccessHandler/LoginFailureHandler.
 */
#[Route('/api/auth/login', name: 'identity_auth_login', methods: ['POST'])]
final class LoginController
{
    public function __invoke(): never
    {
        throw new \LogicException('json_login перехватывает запрос раньше — этот метод недостижим.');
    }
}
