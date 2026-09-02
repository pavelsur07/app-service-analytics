<?php

declare(strict_types=1);

namespace App\Identity\Ui\Controller;

use App\Identity\Application\RegisterClientAccountAction;
use App\Identity\Domain\Administrator;
use App\Identity\Domain\User;
use App\Identity\Ui\Request\RegisterClientAccountRequest;
use App\Identity\Ui\Response\AdminCompanyResponse;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Регистрация клиентского аккаунта (ADR-017): компания и её владелец
 * за одно действие, одной транзакцией.
 *
 * Заменяет пару консольных команд, которой заводили первых клиентов, —
 * вместе с состоянием «компания без участников», которое между ними
 * существовало.
 */
#[Route('/api/admin/companies', name: 'identity_admin_company_register', methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
final class RegisterClientAccountController
{
    public function __construct(
        private readonly Security $security,
        private readonly RegisterClientAccountAction $registerAccount,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    #[OA\RequestBody(content: new OA\JsonContent(
        required: ['name', 'ownerEmail', 'ownerPassword'],
        properties: [
            new OA\Property(property: 'name', type: 'string', maxLength: RegisterClientAccountRequest::MAX_NAME_LENGTH),
            new OA\Property(property: 'ownerEmail', type: 'string', format: 'email'),
            new OA\Property(property: 'ownerPassword', type: 'string', minLength: RegisterClientAccountRequest::MIN_PASSWORD_LENGTH),
        ],
    ))]
    #[OA\Response(
        response: 201,
        description: 'Аккаунт зарегистрирован вместе с владельцем',
        content: new Model(type: AdminCompanyResponse::class),
    )]
    #[OA\Response(
        response: 409,
        description: 'Пользователь с таким email уже существует; компания при этом не создана',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    #[OA\Response(
        response: 422,
        description: 'Некорректное название, email или слишком короткий пароль',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $payload = RegisterClientAccountRequest::fromJson($request->getContent());
        } catch (\InvalidArgumentException $invalid) {
            return $this->error(Response::HTTP_UNPROCESSABLE_ENTITY, $invalid->getMessage(), match ($invalid->getMessage()) {
                'company_name_invalid' => 'Проверьте название компании.',
                'owner_email_invalid' => 'Проверьте адрес электронной почты владельца.',
                'owner_password_too_short' => \sprintf('Пароль владельца короче %d символов.', RegisterClientAccountRequest::MIN_PASSWORD_LENGTH),
                default => 'Тело запроса не разобрано.',
            });
        }

        $actor = $this->security->getUser();
        \assert($actor instanceof Administrator);

        // Хэш считается здесь: Application не имеет доступа
        // к SymfonyComponent (api/deptrac.php). Вспомогательный
        // экземпляр нужен хэшеру только для выбора алгоритма и никуда
        // не сохраняется — тот же приём, что в CreateUserCommand.
        $passwordHash = $this->passwordHasher->hashPassword(
            User::register($payload->ownerEmail, ''),
            $payload->ownerPassword,
        );

        $company = ($this->registerAccount)($payload->companyName, $payload->ownerEmail, $passwordHash, $actor);
        if (null === $company) {
            // Транзакция откатилась целиком: компании без участников
            // после этого отказа не остаётся.
            return $this->error(Response::HTTP_CONFLICT, 'owner_email_taken', 'Пользователь с таким адресом уже существует.');
        }

        return new JsonResponse(
            new AdminCompanyResponse(
                id: $company->id()->toRfc4122(),
                name: $company->name(),
                status: $company->status()->value,
                createdAt: $company->createdAt()->format(\DATE_ATOM),
            ),
            Response::HTTP_CREATED,
        );
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(new ValidationErrorResponse($status, $code, $message), $status);
    }
}
