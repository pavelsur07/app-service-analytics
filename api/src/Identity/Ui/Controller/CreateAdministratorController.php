<?php

declare(strict_types=1);

namespace App\Identity\Ui\Controller;

use App\Identity\Application\CreateAdministratorAction;
use App\Identity\Domain\Administrator;
use App\Identity\Domain\ValueObject\AdminRole;
use App\Identity\Ui\Request\CreateAdministratorRequest;
use App\Identity\Ui\Response\AdministratorResponse;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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
 * Первый экран системного раздела: `SuperAdmin` заводит `Admin`
 * (ADR-017).
 *
 * `#[IsGranted('ROLE_SUPER_ADMIN')]`, а не проверка строки роли
 * в теле метода: голосует встроенный RoleHierarchyVoter, и правило
 * видно на маршруте, а не спрятано в ветке кода. `access_control`
 * пускает сюда любой `ROLE_ADMIN` — этот атрибут сужает до верхней роли,
 * и `Admin` получает 403.
 *
 * Верхнюю роль этим маршрутом не завести: роли в теле запроса нет вовсе,
 * `CreateAdministratorAction` задаёт `Admin` в коде.
 */
#[Route('/api/admin/administrators', name: 'identity_admin_administrator_create', methods: ['POST'])]
#[IsGranted('ROLE_SUPER_ADMIN')]
final class CreateAdministratorController
{
    public function __construct(
        private readonly Security $security,
        private readonly CreateAdministratorAction $createAdministrator,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    #[OA\RequestBody(content: new OA\JsonContent(
        required: ['email', 'password'],
        properties: [
            new OA\Property(property: 'email', type: 'string', format: 'email'),
            new OA\Property(property: 'password', type: 'string', minLength: CreateAdministratorRequest::MIN_PASSWORD_LENGTH),
        ],
    ))]
    #[OA\Response(
        response: 201,
        description: 'Администратор заведён',
        content: new Model(type: AdministratorResponse::class),
    )]
    #[OA\Response(
        response: 403,
        description: 'Заводить администраторов может только SuperAdmin',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    #[OA\Response(
        response: 409,
        description: 'Администратор с таким email уже существует',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    #[OA\Response(
        response: 422,
        description: 'Некорректный email или слишком короткий пароль',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $payload = CreateAdministratorRequest::fromJson($request->getContent());
        } catch (\InvalidArgumentException $invalid) {
            return $this->error(Response::HTTP_UNPROCESSABLE_ENTITY, $invalid->getMessage(), match ($invalid->getMessage()) {
                'email_invalid' => 'Проверьте адрес электронной почты.',
                'password_too_short' => \sprintf('Пароль короче %d символов.', CreateAdministratorRequest::MIN_PASSWORD_LENGTH),
                default => 'Тело запроса не разобрано.',
            });
        }

        $actor = $this->security->getUser();
        // Сюда не доходит никто, кроме ROLE_SUPER_ADMIN, а эту роль
        // отдаёт только Administrator.
        \assert($actor instanceof Administrator);

        // Хэшируется здесь, а не в Action: Application не имеет доступа
        // к SymfonyComponent (api/deptrac.php), и тот же приём уже
        // применён в CreateUserCommand. Хэшеру нужен объект только
        // для выбора алгоритма по конфигурации — этот экземпляр никуда
        // не сохраняется.
        $passwordHash = $this->passwordHasher->hashPassword(
            Administrator::create($payload->email, '', AdminRole::Admin, $actor->id()),
            $payload->password,
        );

        try {
            $administrator = ($this->createAdministrator)($payload->email, $passwordHash, $actor);
        } catch (UniqueConstraintViolationException) {
            // Перехват на вставке, не проверка перед ней (CLAUDE.md §4):
            // два параллельных запроса прошли бы проверку оба.
            return $this->error(Response::HTTP_CONFLICT, 'administrator_email_taken', 'Администратор с таким адресом уже заведён.');
        }

        return new JsonResponse(
            new AdministratorResponse(
                id: $administrator->id()->toRfc4122(),
                email: $administrator->email(),
                role: $administrator->role()->value,
                createdAt: $administrator->createdAt()->format(\DATE_ATOM),
            ),
            Response::HTTP_CREATED,
        );
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(new ValidationErrorResponse($status, $code, $message), $status);
    }
}
