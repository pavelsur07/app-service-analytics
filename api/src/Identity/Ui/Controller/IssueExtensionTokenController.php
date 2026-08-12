<?php

declare(strict_types=1);

namespace App\Identity\Ui\Controller;

use App\Identity\Application\IssueExtensionTokenAction;
use App\Identity\Domain\User;
use App\Identity\Ui\Response\IssueExtensionTokenResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Выпуск токена расширения браузера (ADR-010). Идёт под сессией, а не
 * под токеном: секрет получает человек в приложении и передаёт его
 * расширению — расширение не может выпустить себе токен само.
 *
 * companyId первым сегментом маршрута (CLAUDE.md §1); 403 для чужой
 * компании отдаёт CompanyAccessSubscriber, до этого контроллера запрос
 * не доходит. 401 без сессии — security.access_control (^/api/companies/).
 */
#[Route(
    '/api/companies/{companyId}/extension-tokens',
    name: 'identity_extension_token_issue',
    requirements: ['companyId' => Requirement::UUID],
    methods: ['POST'],
)]
final class IssueExtensionTokenController
{
    public function __construct(
        private readonly Security $security,
        private readonly IssueExtensionTokenAction $issue,
    ) {
    }

    #[OA\Response(
        response: 201,
        description: 'Токен выпущен. Поле token отдаётся единственный раз и больше не восстанавливается.',
        content: new Model(type: IssueExtensionTokenResponse::class),
    )]
    public function __invoke(string $companyId): JsonResponse
    {
        $user = $this->security->getUser();
        \assert($user instanceof User);

        $issued = ($this->issue)(Uuid::fromString($companyId), $user->id());

        return new JsonResponse(
            new IssueExtensionTokenResponse(
                id: $issued->token->id()->toRfc4122(),
                token: $issued->plaintext,
                tokenPrefix: $issued->token->tokenPrefix(),
                expiresAt: $issued->token->expiresAt()->format(\DATE_ATOM),
            ),
            Response::HTTP_CREATED,
        );
    }
}
