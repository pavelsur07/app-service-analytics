<?php

declare(strict_types=1);

namespace App\Identity\Ui\Security;

use App\Shared\Ui\Response\ValidationErrorResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/**
 * Отказ предъявленному токену сервисного контура (firewall
 * `internal_service`). Ветка без заголовка сюда не попадает — её 401
 * отдаёт ApiAuthenticationEntryPoint, как у `extension`. Причина
 * не детализируется: чужой, короткий и токен другого направления
 * снаружи неотличимы.
 */
final class InternalServiceAuthenticationFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new JsonResponse(
            new ValidationErrorResponse(
                status: Response::HTTP_UNAUTHORIZED,
                code: 'invalid_service_token',
                message: 'Service token is missing or invalid.',
            ),
            Response::HTTP_UNAUTHORIZED,
            ['WWW-Authenticate' => 'Bearer'],
        );
    }
}
