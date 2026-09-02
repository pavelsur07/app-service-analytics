<?php

declare(strict_types=1);

namespace App\Identity\Ui\Controller;

use App\Identity\Application\ResendEmailVerificationAction;
use App\Identity\Ui\Request\ResendEmailVerificationRequest;
use App\Identity\Ui\Response\SelfRegistrationResponse;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/auth/email-verification/resend', name: 'identity_email_verification_resend', methods: ['POST'])]
final readonly class ResendEmailVerificationController
{
    public function __construct(
        private ResendEmailVerificationAction $resend,
    ) {
    }

    #[OA\RequestBody(content: new OA\JsonContent(
        required: ['email'],
        properties: [new OA\Property(property: 'email', type: 'string', format: 'email')],
    ))]
    #[OA\Response(response: 202, description: 'Заявка принята', content: new Model(type: SelfRegistrationResponse::class))]
    #[OA\Response(response: 422, description: 'Некорректный email', content: new Model(type: ValidationErrorResponse::class))]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $payload = ResendEmailVerificationRequest::fromJson($request->getContent());
        } catch (\InvalidArgumentException $invalid) {
            return new JsonResponse(
                new ValidationErrorResponse(
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                    $invalid->getMessage(),
                    'email_invalid' === $invalid->getMessage()
                        ? 'Проверьте адрес электронной почты.'
                        : 'Тело запроса не разобрано.',
                ),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        ($this->resend)($payload->email, new \DateTimeImmutable());

        return new JsonResponse(
            new SelfRegistrationResponse(SelfRegistrationResponse::ACCEPTED_MESSAGE),
            Response::HTTP_ACCEPTED,
        );
    }
}
