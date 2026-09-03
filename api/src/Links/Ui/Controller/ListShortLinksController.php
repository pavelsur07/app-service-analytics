<?php

declare(strict_types=1);

namespace App\Links\Ui\Controller;

use App\Links\Infrastructure\Query\AdminShortLinkRow;
use App\Links\Infrastructure\Query\AllShortLinksForAdminQuery;
use App\Links\Ui\Response\ShortLinkListResponse;
use App\Links\Ui\Response\ShortLinkResponse;
use App\Shared\Ui\QueryParameter;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/links', name: 'links_admin_list', methods: ['GET'])]
#[IsGranted('ROLE_ADMIN')]
final readonly class ListShortLinksController
{
    public function __construct(
        private AllShortLinksForAdminQuery $links,
        private string $linksPublicBaseUrl,
    ) {
    }

    #[OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1, minimum: 1))]
    #[OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: AllShortLinksForAdminQuery::DEFAULT_LIMIT, maximum: AllShortLinksForAdminQuery::MAX_LIMIT, minimum: 1))]
    #[OA\Response(response: 200, description: 'Страница коротких ссылок', content: new Model(type: ShortLinkListResponse::class))]
    #[OA\Response(response: 422, description: 'Некорректная пагинация', content: new Model(type: ValidationErrorResponse::class))]
    public function __invoke(Request $request): JsonResponse
    {
        $page = QueryParameter::int($request, 'page', 1);
        if (null === $page || $page < 1) {
            return $this->error('page_invalid', 'Номер страницы — целое число от 1.');
        }

        $limit = QueryParameter::int($request, 'limit', AllShortLinksForAdminQuery::DEFAULT_LIMIT);
        if (null === $limit || $limit < 1) {
            return $this->error('limit_invalid', 'Размер страницы — целое число от 1.');
        }
        if ($limit > AllShortLinksForAdminQuery::MAX_LIMIT) {
            return $this->error('limit_too_large', 'Максимальный размер страницы — 200.');
        }

        $total = $this->links->countAll();
        $pages = max(1, (int) ceil($total / $limit));
        if ($total > 0 && $page > $pages) {
            return $this->error('page_out_of_range', "Страниц всего {$pages}.");
        }

        /** @var list<array<string, mixed>> $rawRows */
        $rawRows = $this->links->build()
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        $items = array_map(
            fn (AdminShortLinkRow $row): ShortLinkResponse => ShortLinkResponse::fromRow($row, $this->linksPublicBaseUrl),
            array_map(AllShortLinksForAdminQuery::mapRow(...), $rawRows),
        );

        return new JsonResponse(new ShortLinkListResponse($items, $total, $pages, $page, $limit));
    }

    private function error(string $code, string $message): JsonResponse
    {
        return new JsonResponse(
            new ValidationErrorResponse(Response::HTTP_UNPROCESSABLE_ENTITY, $code, $message),
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
