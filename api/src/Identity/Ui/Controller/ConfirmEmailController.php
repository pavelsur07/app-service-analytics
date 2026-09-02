<?php

declare(strict_types=1);

namespace App\Identity\Ui\Controller;

use App\Identity\Application\ConfirmEmailAction;
use App\Identity\Domain\ValueObject\EmailConfirmationOutcome;
use App\Identity\Domain\ValueObject\EmailVerificationSecret;
use App\Identity\Ui\Request\ConfirmEmailRequest;
use App\Identity\Ui\Response\EmailConfirmationResponse;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/auth/email-verification/confirm', name: 'identity_email_verification_confirm', methods: ['POST'])]
final readonly class ConfirmEmailController
{
    private const string ONBOARDING_PATH = '/onboarding';

    public function __construct(
        private ConfirmEmailAction $confirm,
        private Security $security,
    ) {
    }

    #[OA\RequestBody(content: new OA\JsonContent(
        required: ['token'],
        properties: [new OA\Property(property: 'token', type: 'string')],
    ))]
    #[OA\Response(response: 200, description: 'Email подтверждён, сессия открыта', content: new Model(type: EmailConfirmationResponse::class))]
    #[OA\Response(response: 409, description: 'Токен уже использован', content: new Model(type: EmailConfirmationResponse::class))]
    #[OA\Response(response: 410, description: 'Токен истёк или неизвестен', content: new Model(type: EmailConfirmationResponse::class))]
    #[OA\Response(response: 422, description: 'Некорректный запрос', content: new Model(type: ValidationErrorResponse::class))]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $payload = ConfirmEmailRequest::fromJson($request->getContent());
        } catch (\InvalidArgumentException $invalid) {
            return new JsonResponse(
                new ValidationErrorResponse(
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                    $invalid->getMessage(),
                    'token_invalid' === $invalid->getMessage()
                        ? 'Проверьте ссылку подтверждения.'
                        : 'Тело запроса не разобрано.',
                ),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $result = ($this->confirm)(
            EmailVerificationSecret::fromPlainText($payload->token),
            new \DateTimeImmutable(),
        );

        if (EmailConfirmationOutcome::Confirmed === $result->outcome) {
            \assert(null !== $result->user);
            $this->security->login($result->user, firewallName: 'api');

            return new JsonResponse(
                new EmailConfirmationResponse($result->outcome->value, self::ONBOARDING_PATH),
            );
        }

        $status = EmailConfirmationOutcome::AlreadyConsumed === $result->outcome
            ? Response::HTTP_CONFLICT
            : Response::HTTP_GONE;

        return new JsonResponse(new EmailConfirmationResponse($result->outcome->value, null), $status);
    }
}
