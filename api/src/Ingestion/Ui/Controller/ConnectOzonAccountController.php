<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Controller;

use App\Ingestion\Application\ConnectOzonAccountAction;
use App\Ingestion\Application\ConnectOzonAccountResult;
use App\Ingestion\Ui\Request\ConnectOzonAccountRequest;
use App\Ingestion\Ui\Response\ConnectedAccountResponse;
use App\Shared\Ui\RequestAttributes;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Подключение кабинета при онбординге (ADR-021).
 *
 * До этого эндпоинта самостоятельно зарегистрировавшийся клиент упирался
 * в тупик: аккаунт есть, компания есть, а подключить кабинет нечем.
 *
 * Ключ проверяется у площадки до сохранения (ConnectOzonAccountAction),
 * поэтому 422 здесь означает именно «площадка не приняла ключ»,
 * а 503 — «площадка не ответила», и это разные следующие действия
 * клиента.
 *
 * companyId первым сегментом (§1); 403 для чужой компании отдаёт
 * CompanyAccessSubscriber, до контроллера запрос не доходит.
 */
#[Route(
    '/api/companies/{companyId}/connections',
    name: 'ingestion_company_connection_connect',
    requirements: ['companyId' => Requirement::UUID],
    methods: ['POST'],
)]
final class ConnectOzonAccountController
{
    public function __construct(
        private readonly ConnectOzonAccountAction $connect,
    ) {
    }

    #[OA\RequestBody(content: new OA\JsonContent(
        required: ['name', 'clientId', 'apiKey'],
        properties: [
            new OA\Property(property: 'name', type: 'string', description: 'Название магазина; после создания не меняется (ADR-021)'),
            new OA\Property(property: 'clientId', type: 'string'),
            new OA\Property(property: 'apiKey', type: 'string'),
        ],
    ))]
    #[OA\Response(
        response: 201,
        description: 'Ключ принят площадкой, подключение создано, первичная загрузка поставлена в очередь',
        content: new Model(type: ConnectedAccountResponse::class),
    )]
    #[OA\Response(
        response: 422,
        description: 'Площадка не приняла ключ (целиком или на отдельной области — товары/продажи/расходы/возвраты, код называет какой: credentials_rejected, credentials_rejected_sales, credentials_rejected_expenses, credentials_rejected_returns) либо тело запроса неполное',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    #[OA\Response(
        response: 409,
        description: 'Кабинет уже подключён — к этой или другой компании (ADR-021)',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    #[OA\Response(
        response: 503,
        description: 'Площадка не ответила — повторить позже, ключ выпускать не нужно',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    #[OA\Response(
        response: 403,
        description: 'Пользователь не состоит в этой компании',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    public function __invoke(string $companyId, Request $request): JsonResponse
    {
        try {
            $payload = ConnectOzonAccountRequest::fromJson($request->getContent());
        } catch (\InvalidArgumentException $invalid) {
            return $this->error(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $invalid->getMessage(),
                'Заполните название магазина, Client-Id и Api-Key.',
            );
        }

        // Автора ставит CompanyAccessSubscriber там же, где проверяет
        // членство: сюда запрос без него не доходит.
        $actorUserId = $request->attributes->get(RequestAttributes::ActorUserId);
        \assert(\is_string($actorUserId));

        $outcome = ($this->connect)($companyId, $payload->name, $payload->clientId, $payload->apiKey, $actorUserId);

        return match ($outcome->result) {
            ConnectOzonAccountResult::Connected => $this->created($outcome->accountId, $payload->name),
            // Тот же код, что у замены ключей: у клиента это та же беда
            // и то же следующее действие.
            ConnectOzonAccountResult::Rejected => $this->error(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'credentials_rejected',
                'Площадка не приняла ключ. Проверьте Client-Id и Api-Key в кабинете продавца.',
            ),
            // Ниже — тот же код 422, но своя область и свой текст: клиенту
            // нужно включить конкретное право в кабинете продавца, а не
            // гадать, какое (боевой инцидент, из-за которого проба
            // расширена с одного эндпоинта на все четыре).
            ConnectOzonAccountResult::RejectedSales => $this->error(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'credentials_rejected_sales',
                'У ключа нет права читать продажи. Включите доступ к отправлениям (FBO/FBS) в кабинете продавца и выпустите ключ заново.',
            ),
            ConnectOzonAccountResult::RejectedExpenses => $this->error(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'credentials_rejected_expenses',
                'У ключа нет права читать финансовые начисления. Включите доступ к финансовым отчётам в кабинете продавца и выпустите ключ заново.',
            ),
            ConnectOzonAccountResult::RejectedReturns => $this->error(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'credentials_rejected_returns',
                'У ключа нет права читать возвраты. Включите доступ к возвратам в кабинете продавца и выпустите ключ заново.',
            ),
            ConnectOzonAccountResult::AlreadyConnected => $this->error(
                Response::HTTP_CONFLICT,
                'cabinet_already_connected',
                'Этот кабинет уже подключён. Один кабинет Ozon подключается только к одному аккаунту.',
            ),
            ConnectOzonAccountResult::Unavailable => $this->error(
                Response::HTTP_SERVICE_UNAVAILABLE,
                'marketplace_unavailable',
                'Ozon сейчас не отвечает. Ключ выпускать не нужно — повторите через несколько минут.',
            ),
        };
    }

    private function created(?string $accountId, string $name): JsonResponse
    {
        // Идентификатор приходит вместе с исходом: у Connected он есть
        // по построению (ConnectOzonAccountOutcome::connected).
        \assert(null !== $accountId);

        return new JsonResponse(
            new ConnectedAccountResponse(id: $accountId, name: $name, state: 'active'),
            Response::HTTP_CREATED,
        );
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(new ValidationErrorResponse($status, $code, $message), $status);
    }
}
