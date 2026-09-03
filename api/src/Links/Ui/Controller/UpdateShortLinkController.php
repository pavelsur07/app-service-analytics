<?php

declare(strict_types=1);

namespace App\Links\Ui\Controller;

use App\Links\Application\ShortLinkMutationOutcome;
use App\Links\Application\UpdateShortLinkAction;
use App\Links\Ui\AdminActorId;
use App\Links\Ui\Request\CreateShortLinkRequest;
use App\Links\Ui\Request\UpdateShortLinkRequest;
use App\Links\Ui\Response\ShortLinkResponse;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(
    '/api/admin/links/{id}',
    name: 'links_admin_update',
    requirements: ['id' => Requirement::UUID],
    methods: ['POST'],
)]
#[IsGranted('ROLE_ADMIN')]
final readonly class UpdateShortLinkController
{
    public function __construct(
        private UpdateShortLinkAction $updateLink,
        private AdminActorId $actorId,
        private string $linksPublicBaseUrl,
    ) {
    }

    #[OA\RequestBody(content: new OA\JsonContent(
        required: ['name', 'targetUrl', 'version'],
        properties: [
            new OA\Property(property: 'name', type: 'string', maxLength: CreateShortLinkRequest::MAX_NAME_LENGTH),
            new OA\Property(property: 'targetUrl', type: 'string', format: 'uri', maxLength: CreateShortLinkRequest::MAX_TARGET_URL_LENGTH),
            new OA\Property(property: 'version', type: 'integer', minimum: 1),
        ],
    ))]
    #[OA\Response(response: 200, description: 'Короткая ссылка обновлена', content: new Model(type: ShortLinkResponse::class))]
    #[OA\Response(response: 404, description: 'Ссылка не найдена', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 409, description: 'Версия ссылки устарела', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 422, description: 'Название, URL или версия некорректны', content: new Model(type: ValidationErrorResponse::class))]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        try {
            $payload = UpdateShortLinkRequest::fromJson($request->getContent());
        } catch (\InvalidArgumentException $invalid) {
            return $this->error(Response::HTTP_UNPROCESSABLE_ENTITY, $invalid->getMessage(), 'Проверьте название, адрес назначения и версию.');
        }

        $result = ($this->updateLink)(
            $id,
            $payload->name,
            $payload->targetUrl,
            $payload->version,
            ($this->actorId)(),
        );

        return match ($result->outcome) {
            ShortLinkMutationOutcome::Saved, ShortLinkMutationOutcome::Unchanged => new JsonResponse(
                ShortLinkResponse::fromEntity($result->link ?? throw new \LogicException('Successful mutation has no link.'), $this->linksPublicBaseUrl),
            ),
            ShortLinkMutationOutcome::NotFound => $this->error(Response::HTTP_NOT_FOUND, 'link_not_found', 'Короткая ссылка не найдена.'),
            ShortLinkMutationOutcome::VersionConflict => $this->error(Response::HTTP_CONFLICT, 'version_conflict', 'Ссылку изменил кто-то ещё. Обновите страницу и повторите.'),
        };
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(new ValidationErrorResponse($status, $code, $message), $status);
    }
}
