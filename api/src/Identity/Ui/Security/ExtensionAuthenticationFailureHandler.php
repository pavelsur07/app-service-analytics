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
 * Отказ, когда заголовок Authorization предъявлен, но токен не подошёл:
 * AccessTokenAuthenticator бросает исключение внутри authenticate(),
 * и без этого обработчика ответ уходит с пустым телом и одним лишь
 * заголовком WWW-Authenticate.
 *
 * Вторая ветка отказа — заголовка нет вовсе — сюда не попадает:
 * supports() возвращает false, аутентификатор не запускается, и 401
 * отдаёт ApiAuthenticationEntryPoint (security.yaml, firewall `extension`).
 * Поэтому нужны обе настройки, а не одна.
 *
 * Отдельный обработчик, не LoginFailureHandler: одинаковая форма ответа
 * сегодня не делает их одним контрактом — там вход по паролю с правилом
 * ADR-007 про неразличимость причин, здесь предъявление токена. Плюс
 * заголовок WWW-Authenticate, которому на входе по паролю взяться неоткуда.
 *
 * Причина не детализируется: истёк, отозван и «нет такого» снаружи
 * неотличимы (ADR-010).
 */
final class ExtensionAuthenticationFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new JsonResponse(
            new ValidationErrorResponse(
                status: Response::HTTP_UNAUTHORIZED,
                code: 'invalid_extension_token',
                message: 'Extension token is missing or invalid.',
            ),
            Response::HTTP_UNAUTHORIZED,
            // RFC 6750: клиент bearer-схемы вправе получить этот заголовок.
            ['WWW-Authenticate' => 'Bearer'],
        );
    }
}
