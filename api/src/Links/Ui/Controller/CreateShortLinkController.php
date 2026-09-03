<?php

declare(strict_types=1);

namespace App\Links\Ui\Controller;

use App\Links\Application\CreateShortLinkAction;
use App\Links\Application\ShortCodeGenerationFailed;
use App\Links\Ui\AdminActorId;
use App\Links\Ui\Request\CreateShortLinkRequest;
use App\Links\Ui\Response\ShortLinkResponse;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/links', name: 'links_admin_create', methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
final readonly class CreateShortLinkController
{
    public function __construct(
        private CreateShortLinkAction $createLink,
        private AdminActorId $actorId,
        private string $linksPublicBaseUrl,
    ) {
    }

    #[OA\RequestBody(content: new OA\JsonContent(
        required: ['name', 'targetUrl'],
        properties: [
            new OA\Property(property: 'name', type: 'string', maxLength: CreateShortLinkRequest::MAX_NAME_LENGTH),
            new OA\Property(property: 'targetUrl', type: 'string', format: 'uri', maxLength: CreateShortLinkRequest::MAX_TARGET_URL_LENGTH),
        ],
    ))]
    #[OA\Response(response: 201, description: 'Короткая ссылка создана', content: new Model(type: ShortLinkResponse::class))]
    #[OA\Response(response: 422, description: 'Название или URL некорректны', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(response: 503, description: 'Не удалось выделить уникальный код', content: new Model(type: ValidationErrorResponse::class))]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $payload = CreateShortLinkRequest::fromJson($request->getContent());
        } catch (\InvalidArgumentException $invalid) {
            return $this->error(Response::HTTP_UNPROCESSABLE_ENTITY, $invalid->getMessage(), 'Проверьте название и адрес назначения.');
        }

        try {
            $link = ($this->createLink)($payload->name, $payload->targetUrl, ($this->actorId)());
        } catch (ShortCodeGenerationFailed) {
            return $this->error(Response::HTTP_SERVICE_UNAVAILABLE, 'short_code_unavailable', 'Не удалось создать свободный короткий код.');
        }

        return new JsonResponse(
            ShortLinkResponse::fromEntity($link, $this->linksPublicBaseUrl),
            Response::HTTP_CREATED,
        );
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(new ValidationErrorResponse($status, $code, $message), $status);
    }
}
