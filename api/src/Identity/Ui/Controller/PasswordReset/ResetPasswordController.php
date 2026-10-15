<?php

declare(strict_types=1);

namespace App\Identity\Ui\Controller\PasswordReset;

use App\Identity\Application\ResetPasswordAction;
use App\Identity\Domain\User;
use App\Identity\Domain\ValueObject\PasswordResetSecret;
use App\Identity\Ui\Request\ResetPasswordRequest;
use App\Identity\Ui\Request\SelfRegistrationRequest;
use App\Identity\Ui\Response\PasswordReset\PasswordResetResponse;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Вторая половина pre-auth границы ADR-037. Сессия не открывается:
 * пользователь входит новым паролем через обычный вход.
 */
#[Route('/api/auth/password-reset/confirm', name: 'identity_password_reset_confirm', methods: ['POST'])]
final readonly class ResetPasswordController
{
    private const string INVALID_TOKEN_MESSAGE = 'Ссылка недействительна или устарела. Запросите новую.';

    public function __construct(
        private ResetPasswordAction $resetPassword,
        private UserPasswordHasherInterface $passwordHasher,
        #[Autowire(service: 'limiter.password_reset_confirm_ip')]
        private RateLimiterFactoryInterface $confirmIpLimiter,
        private string $kernelSecret,
    ) {
    }

    #[OA\RequestBody(content: new OA\JsonContent(
        required: ['token', 'password'],
        properties: [
            new OA\Property(property: 'token', type: 'string'),
            new OA\Property(
                property: 'password',
                type: 'string',
                minLength: SelfRegistrationRequest::MIN_PASSWORD_LENGTH,
                maxLength: ResetPasswordRequest::MAX_PASSWORD_LENGTH,
            ),
        ],
    ))]
    #[OA\Response(response: 200, description: 'Пароль изменён, прежние сессии завершены', content: new Model(type: PasswordResetResponse::class))]
    #[OA\Response(response: 422, description: 'Ссылка недействительна или пароль не подходит', content: new Model(type: ValidationErrorResponse::class))]
    #[OA\Response(
        response: 429,
        description: 'Лимит попыток исчерпан',
        headers: [new OA\Header(
            header: 'Retry-After',
            description: 'Секунды до разрешённой повторной попытки',
            required: true,
            schema: new OA\Schema(type: 'integer', minimum: 0),
        )],
        content: new Model(type: ValidationErrorResponse::class),
    )]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $payload = ResetPasswordRequest::fromJson($request->getContent());
        } catch (\InvalidArgumentException $invalid) {
            return $this->error(Response::HTTP_UNPROCESSABLE_ENTITY, $invalid->getMessage(), match ($invalid->getMessage()) {
                'reset_token_invalid' => self::INVALID_TOKEN_MESSAGE,
                'password_too_short' => \sprintf('Пароль короче %d символов.', SelfRegistrationRequest::MIN_PASSWORD_LENGTH),
                'password_too_long' => 'Пароль слишком длинный.',
                default => 'Тело запроса не разобрано.',
            });
        }

        // Лимит — до хэширования: иначе endpoint стал бы усилителем argon2.
        $limit = $this->confirmIpLimiter
            ->create(hash_hmac('sha256', 'ip:'.($request->getClientIp() ?? ''), $this->kernelSecret))
            ->consume();
        if (!$limit->isAccepted()) {
            $retryAfter = max(0, $limit->getRetryAfter()->getTimestamp() - time());

            return $this->error(
                Response::HTTP_TOO_MANY_REQUESTS,
                'password_reset_rate_limited',
                'Слишком много попыток. Попробуйте позже.',
                ['Retry-After' => (string) $retryAfter],
            );
        }

        $passwordHash = $this->passwordHasher->hashPassword(User::register('password-reset@conwix.invalid', ''), $payload->password);

        $changed = ($this->resetPassword)(
            PasswordResetSecret::fromPlainText($payload->token),
            $passwordHash,
            new \DateTimeImmutable(),
        );
        if (!$changed) {
            return $this->error(Response::HTTP_UNPROCESSABLE_ENTITY, 'reset_token_invalid', self::INVALID_TOKEN_MESSAGE);
        }

        return new JsonResponse(new PasswordResetResponse(PasswordResetResponse::PASSWORD_CHANGED));
    }

    /**
     * @param array<string, string> $headers
     */
    private function error(int $status, string $code, string $message, array $headers = []): JsonResponse
    {
        return new JsonResponse(new ValidationErrorResponse($status, $code, $message), $status, $headers);
    }
}
