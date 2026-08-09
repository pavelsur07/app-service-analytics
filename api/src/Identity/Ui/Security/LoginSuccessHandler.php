<?php

declare(strict_types=1);

namespace App\Identity\Ui\Security;

use App\Identity\Domain\User;
use App\Identity\Ui\Response\LoginResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

/**
 * Минимальное подтверждение входа — список компаний не здесь: одна точка
 * правды, GET /api/auth/me (используется и после логина, и при загрузке
 * приложения для проверки живой сессии).
 */
final class LoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        $user = $token->getUser();
        \assert($user instanceof User);

        return new JsonResponse(new LoginResponse(email: $user->email()));
    }
}
