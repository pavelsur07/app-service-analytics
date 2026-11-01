<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Infrastructure\Api;

use App\ProductResearchOzon\Domain\CatalogSyncFailure;
use App\ProductResearchOzon\Domain\CategorySource;
use App\ProductResearchOzon\Domain\ValueObject\CatalogPage;
use App\ProductResearchOzon\Infrastructure\Config\CatalogContext;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** Used by the company-scoped background command, never by a seller controller. */
final readonly class HttpCategorySource implements CategorySource
{
    private const string BASE = 'http://product-research-ozon:8080/internal/v1/companies/';

    public function __construct(private HttpClientInterface $client, private string $researchServiceToken, private CatalogContext $context)
    {
    }

    public function page(string $companyId, ?string $cursor): CatalogPage
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $companyId)) {
            throw new CatalogSyncFailure('invalid_company');
        }
        if (\strlen($this->researchServiceToken) < 32 || \strlen($this->researchServiceToken) > 256 || 1 !== preg_match('/^[!-~]+$/D', $this->researchServiceToken)) {
            throw new CatalogSyncFailure('catalog_service_not_configured');
        }
        $response = null;
        try {
            $query = ['limit' => 100];
            if (null !== $cursor) {
                $query['cursor'] = $cursor;
            }
            $response = $this->client->request('GET', self::BASE.$companyId.'/categories', ['auth_bearer' => $this->researchServiceToken, 'query' => $query, 'max_redirects' => 0, 'timeout' => 20.0, 'max_duration' => 20.0, 'headers' => ['Accept' => 'application/json']]);
            if (200 !== $response->getStatusCode()) {
                throw new CatalogSyncFailure(409 === $response->getStatusCode() ? 'catalog_cursor_conflict' : 'catalog_service_unavailable', self::retryAt($response));
            }
            if (($response->getHeaders(false)['x-research-source-id'][0] ?? null) !== $this->context->sourceId()) {
                throw new CatalogSyncFailure('catalog_source_mismatch');
            }
            $body = '';
            foreach ($this->client->stream($response, 20.0) as $chunk) {
                if ($chunk->isTimeout()) {
                    throw new CatalogSyncFailure('catalog_service_timeout');
                }
                $body .= $chunk->getContent();
                if (\strlen($body) > 1048576) {
                    throw new CatalogSyncFailure('catalog_response_too_large');
                }
            }

            return self::decode($companyId, $body);
        } catch (TransportExceptionInterface) {
            throw new CatalogSyncFailure('catalog_service_unavailable');
        } finally {
            $response?->cancel();
        }
    }

    private static function decode(string $companyId, string $body): CatalogPage
    {
        try {
            $data = json_decode($body, false, 16, \JSON_THROW_ON_ERROR);
            if (!$data instanceof \stdClass) {
                throw new CatalogSyncFailure('invalid_catalog_response');
            }
            $values = get_object_vars($data);
            if (6 !== \count($values) || [] !== array_diff(array_keys($values), ['schema_version', 'company_id', 'revision', 'generated_at', 'items', 'next_cursor']) || '1' !== ($values['schema_version'] ?? null) || $companyId !== ($values['company_id'] ?? null)) {
                throw new CatalogSyncFailure('invalid_catalog_response');
            }
            $revision = $values['revision'];
            if (\is_float($revision) && is_finite($revision) && floor($revision) === $revision && $revision >= 1 && $revision <= 2147483647) {
                $revision = (int) $revision;
            }
            $generatedAt = $values['generated_at'];
            $items = $values['items'];
            $next = $values['next_cursor'];
            if (!\is_int($revision) || !\is_string($generatedAt) || !\is_array($items) || !array_is_list($items) || (null !== $next && !\is_string($next)) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $generatedAt)) {
                throw new CatalogSyncFailure('invalid_catalog_response');
            }
            $date = new \DateTimeImmutable($generatedAt);
            if (false !== \DateTimeImmutable::getLastErrors()) {
                throw new CatalogSyncFailure('invalid_catalog_response');
            }
            $paths = [];
            foreach ($items as $item) {
                if (!$item instanceof \stdClass || ['path'] !== array_keys(get_object_vars($item)) || !\is_string($item->path)) {
                    throw new CatalogSyncFailure('invalid_catalog_response');
                }
                $paths[] = $item->path;
            }

            return new CatalogPage($revision, $date, $paths, $next);
        } catch (\JsonException|\DateMalformedStringException) {
            throw new CatalogSyncFailure('invalid_catalog_response');
        }
    }

    private static function retryAt(ResponseInterface $response): ?\DateTimeImmutable
    {
        $header = $response->getHeaders(false)['retry-after'][0] ?? null;
        if (null === $header) {
            return null;
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if (1 === preg_match('/^[0-9]{1,12}$/D', $header)) {
            return $now->modify('+'.$header.' seconds');
        }
        $date = \DateTimeImmutable::createFromFormat(\DATE_RFC7231, $header, new \DateTimeZone('UTC'));

        return false === $date ? null : max($now, $date);
    }
}
