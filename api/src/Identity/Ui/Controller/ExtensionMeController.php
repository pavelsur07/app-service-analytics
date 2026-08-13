<?php

declare(strict_types=1);

namespace App\Identity\Ui\Controller;

use App\Identity\Domain\ExtensionTokenRepository;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Query\CompanyNameQuery;
use App\Identity\Infrastructure\Security\ExtensionTokenRequestAttributes;
use App\Identity\Ui\Response\ExtensionMeResponse;
use App\Identity\Ui\Response\MeCompanyResponse;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * «Кто я и с какой компанией работаю» для расширения браузера (ADR-010) —
 * аналог /api/auth/me, но под токеном и с одной компанией: токен привязан
 * к одной, выбирать не из чего.
 *
 * Один этот эндпоинт проверяет всю цепочку подключения: выпуск секрета,
 * его передачу в расширение, хранение, заголовок Authorization,
 * host_permissions и TLS. Поэтому же он обновляет last_seen_at — видно,
 * у кого расширение стоит и работает, без отдельной телеметрии.
 *
 * 401 без токена и с недействительным токеном отдаёт firewall `extension`
 * (security.yaml) раньше, чем запрос доходит сюда; тело JSON, не HTML —
 * ApiAuthenticationEntryPoint.
 */
#[Route('/api/extension/me', name: 'identity_extension_me', methods: ['GET'])]
final class ExtensionMeController
{
    public function __construct(
        private readonly Security $security,
        private readonly CompanyNameQuery $companyNames,
        private readonly ExtensionTokenRepository $tokens,
    ) {
    }

    // security задаётся на операции: отдельного атрибута OA\Security
    // в swagger-php нет, схема ExtensionToken объявлена в nelmio_api_doc.yaml.
    #[OA\Get(security: [['ExtensionToken' => []]])]
    #[OA\Response(
        response: 200,
        description: 'Пользователь и компания, к которым привязан предъявленный токен',
        content: new Model(type: ExtensionMeResponse::class),
    )]
    // 401 — часть контракта, а не деталь реализации: клиент обязан
    // отличить «токен умер, переподключись» от прочих отказов, и форма
    // тела у этого ответа определена (ValidationErrorResponse).
    #[OA\Response(
        response: 401,
        description: 'Токен отсутствует, истёк, отозван или участник исключён из компании',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        \assert($user instanceof User);

        $companyId = $request->attributes->get(ExtensionTokenRequestAttributes::COMPANY_ID);
        $tokenId = $request->attributes->get(ExtensionTokenRequestAttributes::TOKEN_ID);
        \assert(\is_string($companyId) && \is_string($tokenId));

        $companyName = $this->companyNames->find($companyId);
        if (null === $companyName) {
            throw new \RuntimeException('Extension token references a company that no longer exists.');
        }

        // ponytail: пишем на каждый пинг. Расширение ходит сюда по будильнику,
        // счёт идёт на единицы запросов в час на пользователя. Станет заметно —
        // обновлять не чаще раза в N минут, сравнив с текущим last_seen_at.
        $token = $this->tokens->get($companyId, Uuid::fromString($tokenId));
        if (null !== $token) {
            $token->markSeen(new \DateTimeImmutable());
            $this->tokens->save($token);
        }

        return new JsonResponse(new ExtensionMeResponse(
            email: $user->email(),
            company: new MeCompanyResponse(id: $companyId, name: $companyName),
        ));
    }
}
