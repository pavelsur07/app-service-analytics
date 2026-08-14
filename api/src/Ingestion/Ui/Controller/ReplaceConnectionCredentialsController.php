<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Controller;

use App\Ingestion\Application\ReplaceCredentialsResult;
use App\Ingestion\Application\ReplaceOzonCredentialsAction;
use App\Ingestion\Ui\Request\ReplaceCredentialsRequest;
use App\Ingestion\Ui\Response\ReplacedCredentialsResponse;
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
 * Замена ключей подключения клиентом (ADR-007).
 *
 * До этого эндпоинта письмо о сломанном подключении заканчивалось
 * словами «напишите нам»: клиент видел проблему, знал решение и не мог
 * его применить. Ручной шаг в середине самообслуживания — это не «пока
 * так», а причина, по которой сломанное подключение живёт днями.
 *
 * Ключ проверяется у площадки до сохранения (ReplaceOzonCredentialsAction),
 * поэтому 422 здесь означает именно «площадка не приняла ключ», а не
 * «сохранили, посмотрим позже».
 *
 * companyId первым сегментом (§1); 403 для чужой компании отдаёт
 * CompanyAccessSubscriber, до контроллера запрос не доходит.
 */
#[Route(
    '/api/companies/{companyId}/connections/{marketplaceAccountId}/credentials',
    name: 'ingestion_connection_credentials_replace',
    requirements: ['companyId' => Requirement::UUID, 'marketplaceAccountId' => Requirement::UUID],
    methods: ['PUT'],
)]
final class ReplaceConnectionCredentialsController
{
    public function __construct(
        private readonly ReplaceOzonCredentialsAction $replaceCredentials,
    ) {
    }

    #[OA\RequestBody(content: new OA\JsonContent(
        required: ['clientId', 'apiKey'],
        properties: [
            new OA\Property(property: 'clientId', type: 'string'),
            new OA\Property(property: 'apiKey', type: 'string'),
        ],
    ))]
    #[OA\Response(
        response: 200,
        description: 'Ключ принят площадкой и сохранён; сломанное подключение возвращено в работу',
        content: new Model(type: ReplacedCredentialsResponse::class),
    )]
    #[OA\Response(
        response: 404,
        description: 'У этой компании нет такого подключения',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    #[OA\Response(
        response: 422,
        description: 'Площадка не приняла ключ либо тело запроса неполное',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    #[OA\Response(
        response: 403,
        description: 'Пользователь не состоит в этой компании',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    public function __invoke(string $companyId, string $marketplaceAccountId, Request $request): JsonResponse
    {
        try {
            $credentials = ReplaceCredentialsRequest::fromJson($request->getContent());
        } catch (\InvalidArgumentException $invalid) {
            return $this->error(Response::HTTP_UNPROCESSABLE_ENTITY, $invalid->getMessage(), 'Укажите Client-Id и Api-Key.');
        }

        // Автора ставит CompanyAccessSubscriber там же, где проверяет
        // членство: сюда запрос без него не доходит.
        $actorUserId = $request->attributes->get(RequestAttributes::ActorUserId);
        \assert(\is_string($actorUserId));

        $result = ($this->replaceCredentials)(
            $companyId,
            $marketplaceAccountId,
            $credentials->clientId,
            $credentials->apiKey,
            $actorUserId,
        );

        return match ($result) {
            ReplaceCredentialsResult::Replaced => new JsonResponse(
                new ReplacedCredentialsResponse(id: $marketplaceAccountId, state: 'active'),
            ),
            ReplaceCredentialsResult::Rejected => $this->error(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'credentials_rejected',
                'Площадка не приняла этот ключ. Проверьте Client-Id и Api-Key в кабинете продавца.',
            ),
            ReplaceCredentialsResult::NotFound => $this->error(
                Response::HTTP_NOT_FOUND,
                'connection_not_found',
                'Подключение не найдено.',
            ),
        };
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(new ValidationErrorResponse($status, $code, $message), $status);
    }
}
