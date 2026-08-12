<?php

declare(strict_types=1);

namespace App\Identity\Ui\Controller;

use App\Identity\Application\RevokeExtensionTokenAction;
use App\Identity\Domain\User;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Отзыв токена расширения (ADR-010). companyId первым сегментом
 * (CLAUDE.md §1) — токен чужой компании не отзывается даже по верному id:
 * 403 отдаёт CompanyAccessSubscriber, а токен другой компании внутри
 * доступной не находится репозиторием и превращается в 404.
 */
#[Route(
    '/api/companies/{companyId}/extension-tokens/{id}',
    name: 'identity_extension_token_revoke',
    requirements: ['companyId' => Requirement::UUID, 'id' => Requirement::UUID],
    methods: ['DELETE'],
)]
final class RevokeExtensionTokenController
{
    public function __construct(
        private readonly Security $security,
        private readonly RevokeExtensionTokenAction $revoke,
    ) {
    }

    #[OA\Response(response: 204, description: 'Токен отозван. Повторный отзыв идемпотентен.')]
    #[OA\Response(
        response: 404,
        description: 'Токена с таким id в этой компании нет',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    public function __invoke(string $companyId, string $id): JsonResponse
    {
        $user = $this->security->getUser();
        \assert($user instanceof User);

        if (!($this->revoke)($companyId, Uuid::fromString($id), $user->id())) {
            return new JsonResponse(
                new ValidationErrorResponse(
                    status: Response::HTTP_NOT_FOUND,
                    code: 'extension_token_not_found',
                    message: 'Extension token is not found.',
                ),
                Response::HTTP_NOT_FOUND,
            );
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
