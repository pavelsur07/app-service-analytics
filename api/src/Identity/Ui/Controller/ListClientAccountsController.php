<?php

declare(strict_types=1);

namespace App\Identity\Ui\Controller;

use App\Identity\Infrastructure\Query\AdminCompanyRow;
use App\Identity\Infrastructure\Query\AllCompaniesForAdminQuery;
use App\Identity\Ui\Response\AdminCompanyListResponse;
use App\Identity\Ui\Response\AdminCompanyResponse;
use App\Shared\Ui\QueryParameter;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Список клиентских аккаунтов системного раздела (ADR-017).
 *
 * **Единственный класс, которому разрешено межарендаторное чтение
 * из HTTP.** Deptrac держит его в узком слое IdentityAdminAccountsUi,
 * и только этот слой видит IdentityAdminAccountsQuery. Ui продавца
 * (широкий IdentityUi) запроса не видит — грант выдан одному классу,
 * а не «всей админке», по CLAUDE.md §1: узкий слой дают тому, кому
 * он действительно нужен.
 *
 * Обе роли контура (ADR-017): управление аккаунтами доступно и `Admin`,
 * и `SuperAdmin`, поэтому ROLE_ADMIN, а не верхняя роль.
 */
#[Route('/api/admin/companies', name: 'identity_admin_companies_list', methods: ['GET'])]
#[IsGranted('ROLE_ADMIN')]
final class ListClientAccountsController
{
    public function __construct(
        private readonly AllCompaniesForAdminQuery $companies,
    ) {
    }

    #[OA\Parameter(
        name: 'page',
        in: 'query',
        required: false,
        schema: new OA\Schema(type: 'integer', default: 1, minimum: 1),
    )]
    #[OA\Parameter(
        name: 'limit',
        in: 'query',
        required: false,
        schema: new OA\Schema(type: 'integer', default: AllCompaniesForAdminQuery::DEFAULT_LIMIT, maximum: AllCompaniesForAdminQuery::MAX_LIMIT, minimum: 1),
    )]
    #[OA\Response(
        response: 200,
        description: 'Страница клиентских аккаунтов',
        content: new Model(type: AdminCompanyListResponse::class),
    )]
    #[OA\Response(
        response: 422,
        description: 'Некорректная страница или размер страницы сверх максимума',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $page = QueryParameter::int($request, 'page', 1);
        if (null === $page || $page < 1) {
            return $this->error('page_invalid', 'Номер страницы — целое число от 1.');
        }

        $limit = QueryParameter::int($request, 'limit', AllCompaniesForAdminQuery::DEFAULT_LIMIT);
        if (null === $limit || $limit < 1) {
            return $this->error('limit_invalid', 'Размер страницы — целое число от 1.');
        }
        if ($limit > AllCompaniesForAdminQuery::MAX_LIMIT) {
            // 422, а не молчаливое обрезание до максимума
            // (docs/patterns.md): клиент, попросивший тысячу, должен
            // узнать, что получил не тысячу.
            return $this->error('limit_too_large', \sprintf('Максимальный размер страницы — %d.', AllCompaniesForAdminQuery::MAX_LIMIT));
        }

        $total = $this->companies->countAll();
        $pages = max(1, (int) ceil($total / $limit));

        if ($page > $pages && $total > 0) {
            return $this->error('page_out_of_range', \sprintf('Страниц всего %d.', $pages));
        }

        /** @var list<array<string, mixed>> $rawRows */
        $rawRows = $this->companies->build()
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        $items = array_map(
            static fn (AdminCompanyRow $row): AdminCompanyResponse => new AdminCompanyResponse(
                id: $row->id,
                name: $row->name,
                status: $row->status,
                createdAt: $row->createdAt,
            ),
            array_map(AllCompaniesForAdminQuery::mapRow(...), $rawRows),
        );

        return new JsonResponse(new AdminCompanyListResponse(
            items: $items,
            total: $total,
            pages: $pages,
            page: $page,
            per_page: $limit,
        ));
    }

    private function error(string $code, string $message): JsonResponse
    {
        return new JsonResponse(
            new ValidationErrorResponse(Response::HTTP_UNPROCESSABLE_ENTITY, $code, $message),
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
