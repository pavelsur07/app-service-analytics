<?php

declare(strict_types=1);

namespace App\Identity\Ui\Security;

use App\Shared\Ui\Response\ValidationErrorResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccountStatusException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/**
 * hide_user_not_found (умолчание Symfony — true) уже даёт одинаковое
 * сообщение на "нет пользователя" и "неверный пароль": getMessageKey()
 * всегда "Invalid credentials." для обоих случаев — различать их здесь
 * не нужно и нельзя (ADR-007).
 */
final class LoginFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $message = $exception instanceof AccountStatusException
            ? 'Invalid credentials.'
            : $exception->getMessageKey();

        return new JsonResponse(
            new ValidationErrorResponse(status: Response::HTTP_UNAUTHORIZED, code: 'invalid_credentials', message: $message),
            Response::HTTP_UNAUTHORIZED,
        );
    }
}
