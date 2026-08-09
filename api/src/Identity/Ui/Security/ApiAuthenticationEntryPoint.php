<?php

declare(strict_types=1);

namespace App\Identity\Ui\Security;

use App\Shared\Ui\Response\ValidationErrorResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Без него неаутентифицированный запрос к защищённому /api/-маршруту
 * получает HTML debug-страницу Symfony по умолчанию — не подходит для
 * JSON API, и редирект на форму входа тоже не годится (нет HTML-формы,
 * фронтенд — SPA).
 */
final class ApiAuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new JsonResponse(
            new ValidationErrorResponse(
                status: Response::HTTP_UNAUTHORIZED,
                code: 'unauthenticated',
                message: 'Full authentication is required to access this resource.',
            ),
            Response::HTTP_UNAUTHORIZED,
        );
    }
}
