<?php

declare(strict_types=1);

namespace App\Identity\Ui\Security;

use App\Identity\Domain\Administrator;
use App\Identity\Ui\Response\AdminMeResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

/**
 * Отдельный обработчик, а не общий с LoginSuccessHandler: тот утверждает
 * `$user instanceof User` и на администраторе упал бы. Разные контуры —
 * разные типы пользователя (ADR-007), и общий обработчик пришлось бы
 * ветвить по типу, то есть держать знание об обоих контурах в одном месте.
 *
 * Роль в ответе — чтобы админка знала, показывать ли раздел управления
 * администраторами, не делая второго запроса сразу после входа.
 */
final class AdminLoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        $administrator = $token->getUser();
        \assert($administrator instanceof Administrator);

        return new JsonResponse(new AdminMeResponse(
            email: $administrator->email(),
            role: $administrator->role()->value,
        ));
    }
}
