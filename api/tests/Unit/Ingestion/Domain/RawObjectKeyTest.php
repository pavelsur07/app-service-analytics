<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Domain;

use App\Ingestion\Domain\RawObjectKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * ADR-024: ключ объекта сырья. Компания — первый сегмент после префикса:
 * на этом держится изоляция арендаторов в бакете.
 */
final class RawObjectKeyTest extends TestCase
{
    private const string HASH = '5f1c0e0b8d0a4f3e9c2b7a6d5e4f3a2b1c0d9e8f7a6b5c4d3e2f1a0b9c8d7e6f';

    public function testBuildsKeyFromDocumentFields(): void
    {
        $key = RawObjectKey::for(
            Uuid::fromString('0191a3b4-0000-7000-8000-000000000001'),
            Uuid::fromString('0192b4c5-0000-7000-8000-000000000002'),
            'ozon_posting_fbo_list',
            new \DateTimeImmutable('2026-09-04'),
            self::HASH,
            'json',
        );

        self::assertSame(
            'conwix/raw/v1/companies/0191a3b4-0000-7000-8000-000000000001/accounts/0192b4c5-0000-7000-8000-000000000002/ozon_posting_fbo_list/2026/09/04/'.self::HASH.'.json.gz',
            $key->toString(),
        );
    }

    public function testDifferentCompaniesNeverShareAPrefix(): void
    {
        $account = Uuid::v7();
        $period = new \DateTimeImmutable('2026-09-04');
        $first = RawObjectKey::for(Uuid::v7(), $account, 'ozon_accrual_by_day', $period, self::HASH, 'json')->toString();
        $second = RawObjectKey::for(Uuid::v7(), $account, 'ozon_accrual_by_day', $period, self::HASH, 'json')->toString();

        $companyPrefix = static fn (string $key): string => implode('/', \array_slice(explode('/', $key), 0, 5));

        self::assertNotSame($companyPrefix($first), $companyPrefix($second));
        self::assertStringStartsWith(RawObjectKey::PREFIX.'/companies/', $first);
    }

    public function testBelongsOnlyToItsCompany(): void
    {
        $company = Uuid::v7();
        $key = RawObjectKey::for($company, Uuid::v7(), 'ozon_accrual_by_day', new \DateTimeImmutable('2026-09-04'), self::HASH, 'json');

        self::assertTrue($key->belongsTo($company->toRfc4122()));
        // Строка в верхнем регистре — тот же идентификатор.
        self::assertTrue($key->belongsTo(strtoupper($company->toRfc4122())));
        self::assertFalse($key->belongsTo(Uuid::v7()->toRfc4122()));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function invalidSegments(): iterable
    {
        yield 'слэш в типе отчёта' => ['ozon/../other', self::HASH, 'json'];
        yield 'заглавные в типе' => ['Ozon_List', self::HASH, 'json'];
        yield 'короткий хэш' => ['ozon_product_list', 'abc', 'json'];
        yield 'хэш в верхнем регистре' => ['ozon_product_list', strtoupper(self::HASH), 'json'];
        yield 'точка в расширении' => ['ozon_product_list', self::HASH, 'json.gz'];
        yield 'пустое расширение' => ['ozon_product_list', self::HASH, ''];
        yield 'перевод строки после типа' => ["ozon_product_list\n", self::HASH, 'json'];
        yield 'перевод строки после хэша' => ['ozon_product_list', self::HASH."\n", 'json'];
        yield 'перевод строки после расширения' => ['ozon_product_list', self::HASH, "json\n"];
    }

    #[DataProvider('invalidSegments')]
    public function testRejectsSegmentsThatCouldEscapeThePrefix(string $reportType, string $hash, string $extension): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RawObjectKey::for(Uuid::v7(), Uuid::v7(), $reportType, new \DateTimeImmutable('2026-09-04'), $hash, $extension);
    }
}
