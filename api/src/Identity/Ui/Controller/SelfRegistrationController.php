<?php

declare(strict_types=1);

namespace App\Identity\Ui\Controller;

use App\Identity\Application\RegisterClientAccountAction;
use App\Identity\Domain\User;
use App\Identity\Ui\Request\SelfRegistrationRequest;
use App\Identity\Ui\Response\SelfRegistrationResponse;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/auth/sign-up', name: 'identity_self_registration', methods: ['POST'])]
final readonly class SelfRegistrationController
{
    public function __construct(
        private RegisterClientAccountAction $registerAccount,
        private UserPasswordHasherInterface $passwordHasher,
        private string $registrationDocumentsVersion,
    ) {
    }

    #[OA\RequestBody(content: new OA\JsonContent(
        required: ['email', 'password', 'companyName', 'legalConsent'],
        properties: [
            new OA\Property(property: 'email', type: 'string', format: 'email'),
            new OA\Property(property: 'password', type: 'string', minLength: SelfRegistrationRequest::MIN_PASSWORD_LENGTH),
            new OA\Property(property: 'companyName', type: 'string', maxLength: SelfRegistrationRequest::MAX_COMPANY_NAME_LENGTH),
            new OA\Property(property: 'legalConsent', type: 'boolean'),
        ],
    ))]
    #[OA\Response(response: 202, description: 'Заявка принята', content: new Model(type: SelfRegistrationResponse::class))]
    #[OA\Response(response: 422, description: 'Некорректные данные или согласие не дано', content: new Model(type: ValidationErrorResponse::class))]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $payload = SelfRegistrationRequest::fromJson($request->getContent());
        } catch (\InvalidArgumentException $invalid) {
            return new JsonResponse(
                new ValidationErrorResponse(
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                    $invalid->getMessage(),
                    $this->validationMessage($invalid->getMessage()),
                ),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        // Сначала вся дешёвая валидация выше, только затем дорогой хэш.
        // Stage 2 добавит перед ним rate limit и SmartCaptcha.
        $passwordHash = $this->passwordHasher->hashPassword(
            User::register($payload->email, ''),
            $payload->password,
        );

        $this->registerAccount->selfRegister(
            $payload->companyName,
            $payload->email,
            $passwordHash,
            new \DateTimeImmutable(),
            $this->registrationDocumentsVersion,
        );

        return new JsonResponse(
            new SelfRegistrationResponse(SelfRegistrationResponse::ACCEPTED_MESSAGE),
            Response::HTTP_ACCEPTED,
        );
    }

    private function validationMessage(string $code): string
    {
        return match ($code) {
            'email_invalid' => 'Проверьте адрес электронной почты.',
            'password_too_short' => \sprintf('Пароль короче %d символов.', SelfRegistrationRequest::MIN_PASSWORD_LENGTH),
            'company_name_invalid' => 'Проверьте название компании.',
            'legal_consent_required' => 'Для регистрации необходимо принять условия.',
            default => 'Тело запроса не разобрано.',
        };
    }
}
