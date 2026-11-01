<?php

declare(strict_types=1);

namespace App\Tests\Unit\ProductResearchOzon;

use App\ProductResearchOzon\Domain\CatalogSyncFailure;
use App\ProductResearchOzon\Domain\ValueObject\CatalogMetadata;
use App\ProductResearchOzon\Infrastructure\Api\HttpCategorySource;
use App\ProductResearchOzon\Infrastructure\Config\CatalogContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\Uuid;

final class CatalogClientTest extends TestCase
{
    private const string SOURCE = '019a3c00-0000-7000-8000-000000000011';

    public function testSourceTimeControlsTtlAndDoesNotSlide(): void
    {
        $at = new \DateTimeImmutable('2026-11-01T10:00:00Z');
        $state = new CatalogMetadata(1, $at);
        self::assertTrue($state->isFresh($at->modify('+24 hours -1 microsecond')));
        self::assertFalse($state->isFresh($at->modify('+24 hours')));
        self::assertFalse($state->isFresh($at->modify('-1 second')));
    }

    public function testSuccessfulPagePreservesRevisionAndEmptyResult(): void
    {
        $company = Uuid::v7()->toRfc4122();
        $response = $this->response(json_encode($this->payload($company), \JSON_THROW_ON_ERROR));
        $source = new HttpCategorySource(new MockHttpClient($response), str_repeat('t', 40), new CatalogContext(self::SOURCE));
        $page = $source->page($company, null);
        self::assertSame(7, $page->revision);
        self::assertSame([], $page->paths);
        self::assertNull($page->nextCursor);
        self::assertSame(0, $response->getRequestOptions()['max_redirects']);
        self::assertStringContainsString('/companies/'.$company.'/categories', $response->getRequestUrl());
    }

    public function testForeignCompanyAndWrongShapeAreRejected(): void
    {
        $company = Uuid::v7()->toRfc4122();
        foreach ([$this->payload(Uuid::v7()->toRfc4122()), [...$this->payload($company), 'items' => new \stdClass()], [...$this->payload($company), 'extra' => true], [...$this->payload($company), 'items' => [['path' => "bad\0path"]]]] as $value) {
            $source = new HttpCategorySource(new MockHttpClient($this->response(json_encode($value, \JSON_THROW_ON_ERROR))), str_repeat('t', 40), new CatalogContext(self::SOURCE));
            try {
                $source->page($company, null);
                self::fail('Invalid source page must fail before publication');
            } catch (CatalogSyncFailure $failure) {
                self::assertSame('invalid_catalog_response', $failure->getMessage());
            }
        }
    }

    public function testErrorAndOversizeNeverExposeBodyOrToken(): void
    {
        $token = str_repeat('private-test-token-', 3);
        foreach ([$this->response($token, 401), $this->response(str_repeat('x', 1048577))] as $response) {
            try {
                new HttpCategorySource(new MockHttpClient($response), $token, new CatalogContext(self::SOURCE))->page(Uuid::v7()->toRfc4122(), null);
                self::fail('Expected safe bounded failure');
            } catch (CatalogSyncFailure $failure) {
                self::assertStringNotContainsString($token, $failure->getMessage());
                self::assertLessThan(80, \strlen($failure->getMessage()));
            }
        }
    }

    public function testSourceHeaderPreventsMisconfiguredConnectionReuse(): void
    {
        $company = Uuid::v7()->toRfc4122();
        $response = new MockResponse(json_encode($this->payload($company), \JSON_THROW_ON_ERROR), ['response_headers' => ['X-Research-Source-Id: '.Uuid::v7()->toRfc4122()]]);
        $this->expectExceptionMessage('catalog_source_mismatch');
        new HttpCategorySource(new MockHttpClient($response), str_repeat('t', 40), new CatalogContext(self::SOURCE))->page($company, null);
    }

    private function response(string $body, int $status = 200): MockResponse
    {
        return new MockResponse($body, ['http_code' => $status, 'response_headers' => ['X-Research-Source-Id: '.self::SOURCE]]);
    }

    /** @return array<string,mixed> */
    private function payload(string $company): array
    {
        return ['schema_version' => '1', 'company_id' => $company, 'revision' => 7, 'generated_at' => '2026-11-01T10:00:00+00:00', 'items' => [], 'next_cursor' => null];
    }
}
