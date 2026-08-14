<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Controller;

use App\Ingestion\Application\CompanyConnectionView;
use App\Ingestion\Application\ListCompanyConnectionsAction;
use App\Ingestion\Ui\Response\ConnectionResponse;
use App\Ingestion\Ui\Response\ConnectionsResponse;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Подключения компании: состояние и свежесть данных.
 *
 * Экран отвечает на два вопроса, на которые сегодня ответить негде:
 * «данные вообще обновляются?» и «что означает письмо про сломанное
 * подключение?». ADR-007 требует явной метки в интерфейсе именно
 * поэтому — уведомление без места, куда прийти, обрывается на письме.
 *
 * Контроллер живёт в Ingestion, хотя подключения принадлежат Identity:
 * свежесть лежит в raw-слое, а Identity в Ingestion не ходит —
 * зависимости строго вниз (см. ListCompanyConnectionsAction).
 *
 * Учётных данных в ответе нет: их не выбирает даже SQL-запрос.
 */
#[Route(
    '/api/companies/{companyId}/connections',
    name: 'ingestion_company_connections',
    requirements: ['companyId' => Requirement::UUID],
    methods: ['GET'],
)]
final class ListConnectionsController
{
    public function __construct(
        private readonly ListCompanyConnectionsAction $listConnections,
    ) {
    }

    #[OA\Response(
        response: 200,
        description: 'Подключения компании с состоянием и моментом последней загрузки по каждой выгрузке',
        content: new Model(type: ConnectionsResponse::class),
    )]
    // 403 — часть контракта, а не деталь реализации: companyId в адресе
    // не означает доступ (CompanyAccessSubscriber), и клиент обязан
    // отличить «не твоя компания» от прочих отказов, чтобы увести
    // человека к списку своих компаний, а не показать пустой экран.
    #[OA\Response(
        response: 403,
        description: 'Пользователь не состоит в этой компании',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    public function __invoke(string $companyId): JsonResponse
    {
        $connections = array_map(
            static fn (CompanyConnectionView $view): ConnectionResponse => new ConnectionResponse(
                id: $view->id,
                marketplace: $view->marketplace,
                externalShopId: $view->externalShopId,
                state: $view->state,
                createdAt: $view->createdAt,
                lastLoadedAt: $view->lastLoadedAt,
                version: $view->version,
            ),
            ($this->listConnections)($companyId),
        );

        return new JsonResponse(new ConnectionsResponse($connections));
    }
}
