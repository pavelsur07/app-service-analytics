<?php

declare(strict_types=1);

namespace App\Tests\Unit\Links\Domain;

use App\Links\Domain\ShortLink;
use App\Links\Domain\ShortLinkStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ShortLinkTest extends TestCase
{
    public function testHumanEditsAreExplicitAndNoOpsStayNoOps(): void
    {
        $createdAt = new \DateTimeImmutable('2026-09-03 09:00:00 UTC');
        $link = ShortLink::create(
            '0Ab9Zxy',
            'September email',
            'https://conwix.com/start',
            Uuid::v7(),
            $createdAt,
        );

        self::assertSame(ShortLinkStatus::Active, $link->status());
        self::assertSame(1, $link->version());
        self::assertFalse($link->changeDetails('September email', 'https://conwix.com/start', $createdAt));
        self::assertTrue($link->changeDetails(
            'September follow-up',
            'https://conwix.com/follow-up',
            $createdAt->modify('+1 hour'),
        ));
        self::assertSame('September follow-up', $link->name());
        self::assertSame('https://conwix.com/follow-up', $link->targetUrl());
        self::assertEquals($createdAt->modify('+1 hour'), $link->updatedAt());

        self::assertTrue($link->changeStatus(ShortLinkStatus::Disabled, $createdAt->modify('+2 hours')));
        self::assertSame(ShortLinkStatus::Disabled, $link->status());
        self::assertEquals($createdAt->modify('+2 hours'), $link->updatedAt());
        self::assertFalse($link->changeStatus(ShortLinkStatus::Disabled, $createdAt->modify('+3 hours')));
        self::assertEquals($createdAt->modify('+2 hours'), $link->updatedAt());
    }
}
