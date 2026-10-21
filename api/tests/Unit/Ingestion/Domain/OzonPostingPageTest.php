<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Domain;

use App\Ingestion\Domain\OzonPostingPage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OzonPostingPageTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../../Fixtures/Marketplace/ozon/';

    public function testFirstPageOfALongDayPointsToTheNext(): void
    {
        $page = OzonPostingPage::fromBody($this->fixture('posting-fbo-list-v3-2026-10-19-page-001.json'));

        self::assertTrue($page->hasNext);
        self::assertNotSame('', $page->cursor);
    }

    public function testLastPageEndsTheDay(): void
    {
        $page = OzonPostingPage::fromBody($this->fixture('posting-fbo-list-v3-2026-10-19-page-002.json'));

        self::assertFalse($page->hasNext);
    }

    /**
     * Ровно 100 отправлений: Ozon отвечает has_next=true, а следующая
     * страница пуста — это тоже конец дня, а не сбой.
     */
    public function testEmptyPageAfterAFullOneEndsTheDay(): void
    {
        self::assertTrue(OzonPostingPage::fromBody($this->fixture('posting-fbo-list-v3-2026-10-20-page-001.json'))->hasNext);
        self::assertFalse(OzonPostingPage::fromBody($this->fixture('posting-fbo-list-v3-2026-10-20-page-002.json'))->hasNext);
    }

    #[DataProvider('brokenPages')]
    public function testRefusesPageItCannotContinueFrom(string $body): void
    {
        $this->expectException(\UnexpectedValueException::class);

        OzonPostingPage::fromBody($body);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function brokenPages(): iterable
    {
        yield 'v2 body' => ['{"result":[]}'];
        yield 'error body' => ['{"code":9,"message":"obsolete method cannot be used"}'];
        yield 'has_next without cursor' => ['{"postings":[],"has_next":true,"cursor":""}'];
        yield 'has_next missing' => ['{"postings":[],"cursor":"abc"}'];
    }

    private function fixture(string $name): string
    {
        $body = file_get_contents(self::FIXTURES.$name);
        self::assertIsString($body);

        return $body;
    }
}
