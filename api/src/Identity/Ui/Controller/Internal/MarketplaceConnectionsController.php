<?php

declare(strict_types=1);

namespace App\Identity\Ui\Controller\Internal;

use App\Identity\Application\ReplaceAdvertisingCredentialsAction;
use App\Identity\Domain\MarketplaceCredentialsEncryptor;
use App\Identity\Infrastructure\Query\ActiveOzonConnectionRow;
use App\Identity\Infrastructure\Query\ActiveOzonConnectionsForServiceQuery;
use App\Shared\Ui\QueryParameter;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Выдача подключений Ozon сервисам вне монолита — сервисный контур
 * (CLAUDE.md §1, четвёртый случай; ADR-043 п. 4). Контракт —
 * `services/_contracts/marketplace-connections/v1`, а не OpenAPI
 * приложения: потребитель — сервис, не фронтенд (маршрут исключён
 * из Nelmio).
 *
 * **Единственное место, где ключи подключений покидают PHP.** Поэтому:
 * - firewall `internal_service` без сессии, токен направления
 *   «сервис → PHP» сравнивается за постоянное время
 *   (InternalServiceTokenHandler); снаружи путь закрыт nginx;
 * - межарендаторный запрос виден только этому классу — узкие слои
 *   Deptrac IdentityServiceConnectionsUi → IdentityServiceConnectionsQuery;
 * - каждое обращение — строка аудита в канале `service_audit`: сколько
 *   подключений отдано и есть ли следующая страница. Ни ключей, ни тела
 *   ответа в журнале и трекере нет.
 *
 * Подключение без пригодных ключей не отдаётся (контракт v1): сервису
 * нечем по нему работать, а пустая запись выглядела бы рабочей.
 */
#[Route('/api/internal/marketplace-connections', name: 'identity_internal_marketplace_connections', methods: ['GET'])]
#[IsGranted('ROLE_INTERNAL_SERVICE')]
#[WithMonologChannel('service_audit')]
final class MarketplaceConnectionsController
{
    private const string SCHEMA_VERSION = '1';

    private const string PROVIDER = 'ozon';

    /**
     * Ресурсы сервиса, доступные с ключом каждого типа (`resource_types`
     * контракта — выводимое поле, не источник истины: что грузить, решает
     * сервис). Имена — словарь ozon-fetcher.
     */
    private const array SELLER_RESOURCES = [
        'ozon.finance.accrual-by-day',
        'ozon.orders.fbo',
        'ozon.products.list',
        'ozon.products.info',
    ];

    private const array PERFORMANCE_RESOURCES = [
        'ozon.ads.campaigns',
        'ozon.ads.expense',
        'ozon.ads.daily',
    ];

    public function __construct(
        private readonly ActiveOzonConnectionsForServiceQuery $connections,
        private readonly MarketplaceCredentialsEncryptor $credentials,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        if (self::PROVIDER !== $request->query->get('provider')) {
            return $this->error('provider_invalid', 'Поддерживается только provider=ozon.');
        }

        $limit = QueryParameter::int($request, 'limit', ActiveOzonConnectionsForServiceQuery::DEFAULT_LIMIT);
        if (null === $limit || $limit < 1) {
            return $this->error('limit_invalid', 'Размер страницы — целое число от 1.');
        }
        if ($limit > ActiveOzonConnectionsForServiceQuery::MAX_LIMIT) {
            return $this->error('limit_too_large', \sprintf('Максимальный размер страницы — %d.', ActiveOzonConnectionsForServiceQuery::MAX_LIMIT));
        }

        $afterId = null;
        if ($request->query->has('cursor')) {
            $afterId = self::decodeCursor($request->query->get('cursor'));
            if (null === $afterId) {
                return $this->error('cursor_invalid', 'Курсор не выдан этим endpoint-ом.');
            }
        }

        /** @var list<array<string, mixed>> $rawRows */
        $rawRows = $this->connections->build($afterId, $limit)->executeQuery()->fetchAllAssociative();
        $rows = array_map(ActiveOzonConnectionsForServiceQuery::mapRow(...), \array_slice($rawRows, 0, $limit));
        $hasNext = \count($rawRows) > $limit;

        $items = [];
        foreach ($rows as $row) {
            $item = $this->connection($row);
            if (null !== $item) {
                $items[] = $item;
            }
        }

        $last = end($rows);
        $nextCursor = $hasNext && false !== $last ? self::encodeCursor($last->marketplaceAccountId) : null;

        $this->logger->info('marketplace connections issued to service', [
            'provider' => self::PROVIDER,
            'connections' => \count($items),
            'has_next_page' => null !== $nextCursor,
        ]);

        return new JsonResponse(
            [
                'schema_version' => self::SCHEMA_VERSION,
                'generated_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->format(\DATE_RFC3339),
                'connections' => $items,
                'next_cursor' => $nextCursor,
            ],
            Response::HTTP_OK,
            ['Cache-Control' => 'no-store'],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function connection(ActiveOzonConnectionRow $row): ?array
    {
        $values = $this->credentials->decrypt($row->credentialsCiphertext, $row->credentialsKeyVersion)->toArray();

        $seller = self::pair($values, 'client_id', 'api_key', 'client_id', 'api_key');
        // Рекламный ключ — только при active у рекламы (ADR-026 п. 1):
        // сломанная реклама лежит в том же шифротексте, но отдавать её
        // значит слать запросы ключом, на который Ozon уже ответил отказом.
        $performance = $row->advertisingActive
            ? self::pair($values, ReplaceAdvertisingCredentialsAction::PerformanceClientIdKey, ReplaceAdvertisingCredentialsAction::PerformanceClientSecretKey, 'client_id', 'client_secret')
            : null;

        if (null === $seller && null === $performance) {
            return null;
        }

        return [
            'marketplace_account_id' => $row->marketplaceAccountId,
            'company_id' => $row->companyId,
            'provider' => self::PROVIDER,
            'updated_at' => $row->updatedAt->format(\DATE_RFC3339),
            'credentials' => [
                'seller' => $seller,
                'performance' => $performance,
            ],
            'resource_types' => [
                ...(null === $seller ? [] : self::SELLER_RESOURCES),
                ...(null === $performance ? [] : self::PERFORMANCE_RESOURCES),
            ],
        ];
    }

    /**
     * Пара ключей, если обе части есть и не пусты; иначе null — у контракта
     * нет «половины ключа».
     *
     * @param array<string, string> $values
     *
     * @return array<string, string>|null
     */
    private static function pair(array $values, string $idKey, string $secretKey, string $idName, string $secretName): ?array
    {
        $id = $values[$idKey] ?? '';
        $secret = $values[$secretKey] ?? '';
        if ('' === $id || '' === $secret) {
            return null;
        }

        return [$idName => $id, $secretName => $secret];
    }

    private static function encodeCursor(string $marketplaceAccountId): string
    {
        return rtrim(strtr(base64_encode($marketplaceAccountId), '+/', '-_'), '=');
    }

    /**
     * Курсор непрозрачен для сервиса, но не для нас: внутри — id последней
     * строки страницы. Всё, что не раскладывается в канонический UUID, —
     * не наш курсор.
     */
    private static function decodeCursor(mixed $cursor): ?string
    {
        if (!\is_string($cursor) || 1 !== preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $cursor)) {
            return null;
        }

        $id = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (false === $id || !Uuid::isValid($id) || Uuid::fromString($id)->toRfc4122() !== $id) {
            return null;
        }

        return $id;
    }

    private function error(string $code, string $message): JsonResponse
    {
        return new JsonResponse(
            new ValidationErrorResponse(Response::HTTP_UNPROCESSABLE_ENTITY, $code, $message),
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
