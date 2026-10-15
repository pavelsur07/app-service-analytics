<?php

declare(strict_types=1);

namespace App\Identity\Ui\Controller\PasswordReset;

use App\Identity\Application\RequestPasswordResetAction;
use App\Identity\Domain\User;
use App\Identity\Ui\Request\RequestPasswordResetRequest;
use App\Identity\Ui\Request\SelfRegistrationRequest;
use App\Identity\Ui\Response\Registration\SelfRegistrationResponse;
use App\Identity\Ui\Security\PublicFormProtection;
use App\Identity\Ui\Security\PublicFormProtectionDecision;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Первая половина pre-auth границы ADR-037. Ответ не зависит от того,
 * есть ли аккаунт с адресом: различие уезжает в письмо.
 */
#[Route('/api/auth/password-reset/request', name: 'identity_password_reset_request', methods: ['POST'])]
final readonly class RequestPasswordResetController
{
    public function __construct(
        private RequestPasswordResetAction $requestReset,
        private PublicFormProtection $passwordResetProtection,
    ) {
    }

    #[OA\RequestBody(content: new OA\JsonContent(
        required: ['email', 'captchaToken'],
        properties: [
            new OA\Property(property: 'email', type: 'string', format: 'email'),
            new OA\Property(property: 'captchaToken', type: 'string', minLength: 1, maxLength: SelfRegistrationRequest::MAX_CAPTCHA_TOKEN_LENGTH),
        ],
    ))]
    #[OA\Response(response: 202, description: 'Заявка принята', content: new Model(type: SelfRegistrationResponse::class))]
    #[OA\Response(response: 422, description: 'Некорректный email или CAPTCHA', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(
        response: 429,
        description: 'Лимит запросов восстановления исчерпан',
        headers: [new OA\Header(
            header: 'Retry-After',
            description: 'Секунды до разрешённой повторной попытки',
            required: true,
            schema: new OA\Schema(type: 'integer', minimum: 0),
        )],
        content: new Model(type: ValidationErrorResponse::class),
    )]
    #[OA\Response(
        response: 503,
        description: 'Проверка CAPTCHA временно недоступна',
        headers: [new OA\Header(
            header: 'Retry-After',
            description: 'Секунды до рекомендуемой повторной попытки',
            required: true,
            schema: new OA\Schema(type: 'integer', minimum: 0),
        )],
        content: new Model(type: ValidationErrorResponse::class),
    )]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $payload = RequestPasswordResetRequest::fromJson($request->getContent());
        } catch (\InvalidArgumentException $invalid) {
            return $this->error(Response::HTTP_UNPROCESSABLE_ENTITY, $invalid->getMessage(), match ($invalid->getMessage()) {
                'email_invalid' => 'Проверьте адрес электронной почты.',
                'captcha_invalid' => 'Проверьте CAPTCHA.',
                default => 'Тело запроса не разобрано.',
            });
        }

        $protection = $this->passwordResetProtection->check(
            User::normalizeEmail($payload->email),
            $request->getClientIp(),
            $payload->captchaToken,
        );
        if (PublicFormProtectionDecision::CaptchaRejected === $protection->decision) {
            return $this->error(Response::HTTP_UNPROCESSABLE_ENTITY, 'captcha_invalid', 'Проверьте CAPTCHA.');
        }
        if (PublicFormProtectionDecision::Unavailable === $protection->decision) {
            return $this->error(
                Response::HTTP_SERVICE_UNAVAILABLE,
                'captcha_unavailable',
                'Проверка CAPTCHA временно недоступна. Попробуйте позже.',
                ['Retry-After' => '30'],
            );
        }
        if (PublicFormProtectionDecision::RateLimited === $protection->decision) {
            $now = new \DateTimeImmutable();
            $retryAfter = max(0, ($protection->retryAfter ?? $now)->getTimestamp() - $now->getTimestamp());

            return $this->error(
                Response::HTTP_TOO_MANY_REQUESTS,
                'password_reset_rate_limited',
                'Слишком много запросов восстановления пароля. Попробуйте позже.',
                ['Retry-After' => (string) $retryAfter],
            );
        }

        ($this->requestReset)($payload->email, new \DateTimeImmutable());

        return new JsonResponse(
            new SelfRegistrationResponse(SelfRegistrationResponse::ACCEPTED_MESSAGE),
            Response::HTTP_ACCEPTED,
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private function error(int $status, string $code, string $message, array $headers = []): JsonResponse
    {
        return new JsonResponse(new ValidationErrorResponse($status, $code, $message), $status, $headers);
    }
}
