<?php

declare(strict_types=1);

namespace App\Identity\Ui\Controller;

use App\Identity\Domain\User;
use App\Identity\Infrastructure\Query\UserCompaniesQuery;
use App\Identity\Infrastructure\Query\UserCompanyRow;
use App\Identity\Ui\Response\MeCompanyResponse;
use App\Identity\Ui\Response\MeResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Одна точка правды "кто я и какие компании доступны" — используется
 * и сразу после входа, и при загрузке приложения (проверка живой сессии).
 * 401 без сессии отдаёт firewall раньше, чем запрос доходит сюда
 * (security.yaml, access_control: /api/auth/me → ROLE_USER).
 */
#[Route('/api/auth/me', name: 'identity_auth_me', methods: ['GET'])]
final class MeController
{
    public function __construct(
        private readonly Security $security,
        private readonly UserCompaniesQuery $userCompanies,
    ) {
    }

    #[OA\Response(
        response: 200,
        description: 'Текущий пользователь и компании, доступные ему по членству',
        content: new Model(type: MeResponse::class),
    )]
    public function __invoke(): JsonResponse
    {
        $user = $this->security->getUser();
        \assert($user instanceof User);

        $companies = array_map(
            static fn (UserCompanyRow $row): MeCompanyResponse => new MeCompanyResponse(id: $row->id, name: $row->name),
            $this->userCompanies->forUser($user->id()->toRfc4122()),
        );

        return new JsonResponse(new MeResponse(email: $user->email(), companies: $companies));
    }
}
