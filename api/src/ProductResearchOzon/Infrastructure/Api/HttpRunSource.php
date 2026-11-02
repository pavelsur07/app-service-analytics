<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Infrastructure\Api;

use App\ProductResearchOzon\Domain\CatalogSyncFailure;
use App\ProductResearchOzon\Domain\MonthSlice;
use App\ProductResearchOzon\Domain\RunSource;
use App\ProductResearchOzon\Domain\RunSyncFailure;
use App\ProductResearchOzon\Domain\ValueObject\RunDelivery;
use App\ProductResearchOzon\Domain\ValueObject\RunMonthState;
use App\ProductResearchOzon\Domain\ValueObject\RunProgress;
use App\ProductResearchOzon\Domain\ValueObject\RunProvenance;
use App\ProductResearchOzon\Infrastructure\Config\CatalogContext;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Background only; bounded metadata, no marketplace bodies or secrets in failures. */
final readonly class HttpRunSource implements RunSource
{
    private const string BASE = 'http://product-research-ozon:8080/internal/v1/companies/';

    public function __construct(private HttpClientInterface $client, private string $researchServiceToken, private CatalogContext $context)
    {
    }

    public function submit(string $companyId, RunDelivery $delivery): void
    {
        if ($companyId !== $delivery->companyId) {
            throw new RunSyncFailure('run_source_mismatch');
        }
        $data = $this->request($companyId, $delivery->runId, $delivery->sourceId, $delivery->payload);
        self::keys($data, ['schema_version', 'company_id', 'run_id', 'status', 'revision']);
        if ('1' !== $data->schema_version || $companyId !== $data->company_id || $delivery->runId !== $data->run_id || 'queued' !== $data->status || 1 !== self::integer($data->revision)) {
            throw new RunSyncFailure('invalid_run_response');
        }
    }

    public function progress(string $companyId, string $runId, string $sourceId): RunProgress
    {
        $data = $this->request($companyId, $runId, $sourceId);
        try {
            self::keys($data, ['schema_version', 'company_id', 'run_id', 'status', 'revision', 'months']);
            if ('2' !== $data->schema_version || $companyId !== $data->company_id || $runId !== $data->run_id || !\is_string($data->status) || !\is_array($data->months) || !array_is_list($data->months) || \count($data->months) > 24) {
                throw new RunSyncFailure('invalid_run_response');
            }
            $months = [];
            foreach ($data->months as $month) {
                self::keys($month, ['period', 'status', 'snapshot_id', 'row_count', 'error_code', 'origin', 'collected_at', 'used_at']);
                self::keys($month->period, ['month', 'date_from', 'date_to', 'days', 'partial']);
                $p = $month->period;
                if (!\is_string($p->month) || !\is_string($p->date_from) || !\is_string($p->date_to) || !\is_bool($p->partial) || !\is_string($month->status) || (null !== $month->snapshot_id && !\is_string($month->snapshot_id)) || (null !== $month->error_code && !\is_string($month->error_code))) {
                    throw new RunSyncFailure('invalid_run_response');
                }
                $provenance = null;
                if ('ready' === $month->status) {
                    if (!\is_string($month->origin) || !\is_string($month->collected_at) || (null !== $month->used_at && !\is_string($month->used_at))) {
                        throw new RunSyncFailure('invalid_run_response');
                    }
                    $provenance = new RunProvenance($month->origin, self::timestamp($month->collected_at), null === $month->used_at ? null : self::timestamp($month->used_at));
                } elseif (null !== $month->origin || null !== $month->collected_at || null !== $month->used_at) {
                    throw new RunSyncFailure('invalid_run_response');
                }
                $months[] = new RunMonthState(new MonthSlice($p->month, $p->date_from, $p->date_to, self::integer($p->days), $p->partial), $month->status, $month->snapshot_id, null === $month->row_count ? null : self::integer($month->row_count), $month->error_code, $provenance);
            }

            return new RunProgress($companyId, $runId, $sourceId, $data->status, self::integer($data->revision), $months);
        } catch (\InvalidArgumentException) {
            throw new RunSyncFailure('invalid_run_response');
        }
    }

    private function request(string $companyId, string $runId, string $sourceId, ?string $payload = null): \stdClass
    {
        if (!RunProgress::validId($companyId) || !RunProgress::validId($runId) || !RunProgress::validId($sourceId)) {
            throw new RunSyncFailure('invalid_run_response');
        }
        try {
            $configured = $this->context->sourceId();
        } catch (CatalogSyncFailure) {
            throw new RunSyncFailure('research_service_not_configured');
        }
        if ($configured !== $sourceId) {
            throw new RunSyncFailure('run_source_mismatch');
        }
        if (\strlen($this->researchServiceToken) < 32 || \strlen($this->researchServiceToken) > 256 || 1 !== preg_match('/^[!-~]+$/D', $this->researchServiceToken)) {
            throw new RunSyncFailure('research_service_not_configured');
        }
        $response = null;
        try {
            $url = (null === $payload ? str_replace('/v1/', '/v2/', self::BASE) : self::BASE).$companyId.'/runs'.(null === $payload ? '/'.$runId : '');
            $options = ['auth_bearer' => $this->researchServiceToken, 'max_redirects' => 0, 'timeout' => 20.0, 'max_duration' => 20.0, 'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json']];
            if (null !== $payload) {
                $options['body'] = $payload;
            }
            $response = $this->client->request(null === $payload ? 'GET' : 'POST', $url, $options);
            if ((null === $payload ? 200 : 202) !== $response->getStatusCode()) {
                throw new RunSyncFailure();
            }
            if (($response->getHeaders(false)['x-research-source-id'][0] ?? null) !== $sourceId) {
                throw new RunSyncFailure('run_source_mismatch');
            }
            $body = '';
            foreach ($this->client->stream($response, 20.0) as $chunk) {
                if ($chunk->isTimeout()) {
                    throw new RunSyncFailure();
                }
                $body .= $chunk->getContent();
                if (\strlen($body) > 65536) {
                    throw new RunSyncFailure('invalid_run_response');
                }
            }
            $decoded = json_decode($body, false, 16, \JSON_THROW_ON_ERROR);
            if (!$decoded instanceof \stdClass) {
                throw new RunSyncFailure('invalid_run_response');
            }

            return $decoded;
        } catch (TransportExceptionInterface) {
            throw new RunSyncFailure();
        } catch (\JsonException) {
            throw new RunSyncFailure('invalid_run_response');
        } finally {
            $response?->cancel();
        }
    }

    /** @param list<string> $keys */
    private static function keys(mixed $object, array $keys): void
    {
        if (!$object instanceof \stdClass || \count(get_object_vars($object)) !== \count($keys) || [] !== array_diff(array_keys(get_object_vars($object)), $keys)) {
            throw new RunSyncFailure('invalid_run_response');
        }
    }

    private static function timestamp(string $value): \DateTimeImmutable
    {
        if (1 !== preg_match('/^20[0-9]{2}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:Z|[+-][0-9]{2}:[0-9]{2})$/D', $value)) {
            throw new RunSyncFailure('invalid_run_response');
        }
        try {
            $date = new \DateTimeImmutable($value);
        } catch (\DateMalformedStringException) {
            throw new RunSyncFailure('invalid_run_response');
        }
        if (false !== \DateTimeImmutable::getLastErrors()) {
            throw new RunSyncFailure('invalid_run_response');
        }

        return $date;
    }

    private static function integer(mixed $value): int
    {
        if (\is_float($value) && is_finite($value) && floor($value) === $value && $value >= 0 && $value <= 2147483647) {
            return (int) $value;
        }
        if (!\is_int($value) || $value < 0 || $value > 2147483647) {
            throw new RunSyncFailure('invalid_run_response');
        }

        return $value;
    }
}
