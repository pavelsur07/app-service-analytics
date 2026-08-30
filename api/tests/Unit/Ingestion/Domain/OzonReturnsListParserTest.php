<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Domain;

use App\Ingestion\Domain\OzonReturnsListParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class OzonReturnsListParserTest extends TestCase
{
    private const string FIXTURE = __DIR__.'/../../../Fixtures/Marketplace/ozon/ozon-buyout-returns.json';

    public function testMapsSyntheticReturnsIncludingDuplicateOrderSkuEventsAndTrace(): void
    {
        $body = file_get_contents(self::FIXTURE);
        self::assertIsString($body);
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $rawDocumentId = Uuid::v7();

        $page = (new OzonReturnsListParser())->parse($body, $companyId, $accountId, $rawDocumentId, 0);

        self::assertCount(6, $page->facts);
        self::assertFalse($page->hasNext);
        self::assertNull($page->lastId);

        $first = $page->facts[0];
        self::assertSame($companyId, $first->companyId());
        self::assertSame($accountId, $first->marketplaceAccountId());
        self::assertSame($rawDocumentId, $first->rawDocumentId());
        self::assertSame('900001', $first->sourceRowId());
        self::assertSame('TEST-MIX-1', $first->orderNumber());
        self::assertSame('100001', $first->marketplaceSku());
        self::assertSame('Cancellation', $first->returnType());
        self::assertSame('Покупатель отказался при вручении: товар не подошел', $first->returnReasonName());
        self::assertSame('TEST-MIX-1-RETURN-1', $first->postingNumber());
        self::assertSame(800001, $first->sourceId());
        self::assertSame(1, $first->quantity());
        self::assertSame(1, $first->visualStatusId());
        self::assertSame('Completed', $first->visualStatus());
        self::assertSame('2026-08-11T10:00:00+00:00', $first->visualStatusChangedAt()->format(\DateTimeInterface::ATOM));

        $samePair = array_values(array_filter(
            $page->facts,
            static fn ($fact): bool => 'TEST-MIX-1' === $fact->orderNumber() && '100001' === $fact->marketplaceSku(),
        ));
        self::assertSame(['900001', '900006'], array_map(static fn ($fact): string => $fact->sourceRowId(), $samePair));
    }

    public function testAcceptsOpaqueCursorThatDecreasesNumericallyButChanges(): void
    {
        $page = $this->parse(json_encode([
            'returns' => [$this->validReturn(['id' => 100])],
            'has_next' => true,
        ], \JSON_THROW_ON_ERROR), previousLastId: 200);

        self::assertTrue($page->hasNext);
        self::assertSame(100, $page->lastId);
    }

    public function testHasNextRequiresANonEmptyPage(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->parse('{"returns":[],"has_next":true}', previousLastId: 1);
    }

    public function testHasNextRequiresChangedCursor(): void
    {
        $body = json_encode([
            'returns' => [$this->validReturn(['id' => 100])],
            'has_next' => true,
        ], \JSON_THROW_ON_ERROR);

        $this->expectException(\UnexpectedValueException::class);
        $this->parse($body, previousLastId: 100);
    }

    #[DataProvider('requiredFieldCases')]
    public function testMissingOrInvalidRequiredFieldFailsLoudly(string $path): void
    {
        $return = $this->validReturn();
        $segments = explode('.', $path);
        $target = &$return;
        foreach ($segments as $index => $segment) {
            if ($index === array_key_last($segments)) {
                unset($target[$segment]);
                break;
            }
            /** @var array<string, mixed> $next */
            $next = &$target[$segment];
            $target = &$next;
        }
        unset($target);

        $this->expectException(\UnexpectedValueException::class);
        $this->parse(json_encode(['returns' => [$return], 'has_next' => false], \JSON_THROW_ON_ERROR));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function requiredFieldCases(): iterable
    {
        foreach ([
            'id',
            'order_number',
            'type',
            'return_reason_name',
            'posting_number',
            'source_id',
            'product.sku',
            'product.quantity',
            'visual.status.id',
            'visual.status.sys_name',
            'visual.change_moment',
        ] as $path) {
            yield $path => [$path];
        }
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function validReturn(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 900001,
            'order_number' => 'TEST-ORDER-1',
            'type' => 'Cancellation',
            'return_reason_name' => 'Покупатель не забрал заказ',
            'posting_number' => 'TEST-ORDER-1-1',
            'source_id' => 800001,
            'product' => ['sku' => 100001, 'quantity' => 1],
            'visual' => [
                'status' => ['id' => 34, 'sys_name' => 'ReturnedToOzon'],
                'change_moment' => '2026-08-11T10:00:00Z',
            ],
        ], $overrides);
    }

    private function parse(string $body, int $previousLastId = 0): \App\Ingestion\Domain\OzonReturnsPage
    {
        return (new OzonReturnsListParser())->parse(
            $body,
            Uuid::v7(),
            Uuid::v7(),
            Uuid::v7(),
            $previousLastId,
        );
    }
}
