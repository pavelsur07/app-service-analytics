<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\ServiceCoverage;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Потребитель контракта service-coverage v1 (`services/_contracts/`,
 * ADR-043 п. 6): сводка покрытия ozon-fetcher для экрана «Покрытие GO».
 *
 * Токен — направления «PHP → сервис» (`OZON_FETCHER_SERVICE_API_TOKEN`).
 * Ни он, ни тело ответа не попадают в журнал: при отказе пишется только
 * причина и HTTP-статус.
 *
 * Отказ сервиса — не исключение, а состояние: экран показывает «сервис
 * не отвечает», и трекер не засоряется недоступностью соседнего процесса
 * (CLAUDE.md, «Наблюдаемость»). Ответ вне контракта — тоже Unavailable:
 * разбор строгий, частично понятая сводка опаснее отсутствующей.
 */
final readonly class OzonFetcherCoverageClient
{
    /**
     * Запрос идёт внутри запроса администратора — короткий потолок
     * без повторов, как у капчи.
     */
    private const float TIMEOUT = 5.0;

    private const array STATUSES = ['done', 'running', 'pending', 'not_ready', 'failed_retryable', 'failed_permanent'];

    private const array TOP_KEYS = ['schema_version', 'service', 'generated_at', 'from', 'to', 'cabinets_total', 'rows'];

    private const array ROW_KEYS = ['resource_type', 'period_key', 'cabinets_expected', 'by_status', 'last_success_at'];

    private string $baseUrl;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        /** `OZON_FETCHER_ENABLED`: включён ровно при `true`, как у ozon-fetcher-compose.sh */
        private string $enabled,
        string $baseUrl,
        #[\SensitiveParameter]
        private string $token,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Окно — дни бизнес-периодов сервиса (МСК), `Y-m-d`, включительно.
     */
    public function fetch(string $from, string $to): ServiceCoverageSnapshot
    {
        // Выключенный сервис не запущен вовсе — без проверки флага экран
        // показывал бы «не отвечает» там, где сервис выключен намеренно.
        if ('true' !== $this->enabled || '' === $this->baseUrl || '' === $this->token) {
            return ServiceCoverageSnapshot::without(ServiceCoverageState::Disabled);
        }

        try {
            $response = $this->httpClient->request('GET', $this->baseUrl.'/internal/coverage', [
                'query' => ['from' => $from, 'to' => $to],
                'auth_bearer' => $this->token,
                'timeout' => self::TIMEOUT,
                'max_duration' => self::TIMEOUT,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (ExceptionInterface) {
            return $this->unavailable('transport');
        }

        if (503 === $status) {
            // Оба кода — штатные состояния сервиса, не отказ.
            return match (self::errorCode($body)) {
                'cabinets_not_ready' => ServiceCoverageSnapshot::without(ServiceCoverageState::NotReady),
                'ingestion_disabled' => ServiceCoverageSnapshot::without(ServiceCoverageState::Disabled),
                default => $this->unavailable('http_status', $status),
            };
        }
        if (200 !== $status) {
            return $this->unavailable('http_status', $status);
        }

        try {
            return self::parse($body);
        } catch (\UnexpectedValueException $e) {
            return $this->unavailable('contract: '.$e->getMessage(), $status);
        }
    }

    /**
     * Разбор по schema.json v1: все поля обязательны, лишних нет, типы
     * и границы — как в схеме. Публичный ради теста потребителя
     * на эталонном example.json.
     *
     * @throws \UnexpectedValueException ответ вне контракта
     */
    public static function parse(string $body): ServiceCoverageSnapshot
    {
        try {
            $payload = json_decode($body, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \UnexpectedValueException('invalid json');
        }
        $payload = self::object($payload, self::TOP_KEYS, 'response');

        if ('1' !== $payload['schema_version']) {
            throw new \UnexpectedValueException('schema_version');
        }
        if (!\is_string($payload['service']) || '' === $payload['service']) {
            throw new \UnexpectedValueException('service');
        }
        self::date($payload['from'], 'from');
        self::date($payload['to'], 'to');
        $generatedAt = self::dateTime($payload['generated_at'], 'generated_at');
        if (null === $generatedAt) {
            throw new \UnexpectedValueException('generated_at');
        }
        if (!\is_array($payload['rows']) || !array_is_list($payload['rows'])) {
            throw new \UnexpectedValueException('rows');
        }

        $rows = [];
        foreach ($payload['rows'] as $raw) {
            $row = self::object($raw, self::ROW_KEYS, 'row');
            if (!\is_string($row['resource_type']) || '' === $row['resource_type']) {
                throw new \UnexpectedValueException('row.resource_type');
            }
            if (!\is_string($row['period_key']) || 1 !== preg_match('/\A(\d{4}-\d{2}-\d{2}|\d{4}-\d{2}|ref)\z/', $row['period_key'])) {
                throw new \UnexpectedValueException('row.period_key');
            }
            $byStatus = self::object($row['by_status'], self::STATUSES, 'row.by_status');

            $rows[] = new ServiceCoverageRow(
                resourceType: $row['resource_type'],
                periodKey: $row['period_key'],
                cabinetsExpected: self::count($row['cabinets_expected'], 'row.cabinets_expected'),
                done: self::count($byStatus['done'], 'done'),
                running: self::count($byStatus['running'], 'running'),
                pending: self::count($byStatus['pending'], 'pending'),
                notReady: self::count($byStatus['not_ready'], 'not_ready'),
                failedRetryable: self::count($byStatus['failed_retryable'], 'failed_retryable'),
                failedPermanent: self::count($byStatus['failed_permanent'], 'failed_permanent'),
                lastSuccessAt: self::dateTime($row['last_success_at'], 'row.last_success_at'),
            );
        }

        return ServiceCoverageSnapshot::ok($generatedAt, self::count($payload['cabinets_total'], 'cabinets_total'), $rows);
    }

    private function unavailable(string $reason, ?int $status = null): ServiceCoverageSnapshot
    {
        $this->logger->warning('ozon-fetcher coverage is unavailable', ['reason' => $reason, 'status' => $status]);

        return ServiceCoverageSnapshot::without(ServiceCoverageState::Unavailable);
    }

    private static function errorCode(string $body): ?string
    {
        try {
            $payload = json_decode($body, true, 4, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        $code = \is_array($payload) && \is_array($payload['error'] ?? null) ? ($payload['error']['code'] ?? null) : null;

        return \is_string($code) ? $code : null;
    }

    /**
     * Объект с ровно этими ключами (additionalProperties: false).
     *
     * @param list<string> $keys
     *
     * @return array<string, mixed>
     */
    private static function object(mixed $value, array $keys, string $field): array
    {
        if (!\is_array($value) || array_is_list($value) && [] !== $value) {
            throw new \UnexpectedValueException($field);
        }
        $actual = array_map(strval(...), array_keys($value));
        sort($actual);
        $expected = $keys;
        sort($expected);
        if ($actual !== $expected) {
            throw new \UnexpectedValueException($field.' keys');
        }

        /* @var array<string, mixed> $value */
        return $value;
    }

    private static function count(mixed $value, string $field): int
    {
        if (!\is_int($value) || $value < 0) {
            throw new \UnexpectedValueException($field);
        }

        return $value;
    }

    private static function date(mixed $value, string $field): void
    {
        $parsed = \is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if (false === $parsed || $parsed->format('Y-m-d') !== $value) {
            throw new \UnexpectedValueException($field);
        }
    }

    private static function dateTime(mixed $value, string $field): ?\DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }
        // format: date-time схемы — RFC 3339, с долями секунды или без.
        foreach ([\DateTimeInterface::RFC3339, \DateTimeInterface::RFC3339_EXTENDED] as $format) {
            $parsed = \is_string($value) ? \DateTimeImmutable::createFromFormat($format, $value) : false;
            if (false !== $parsed) {
                return $parsed;
            }
        }

        throw new \UnexpectedValueException($field);
    }
}
