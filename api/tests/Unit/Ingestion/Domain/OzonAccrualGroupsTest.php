<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Domain;

use App\Ingestion\Domain\OzonAccrualGroups;
use App\Ingestion\Domain\OzonFeeTypeNames;
use PHPUnit\Framework\TestCase;

final class OzonAccrualGroupsTest extends TestCase
{
    public function testEveryMappedTypeIsAKnownOzonType(): void
    {
        // Соответствие снято с выгрузки кабинета; тип, которого нет
        // в справочнике, означал бы опечатку в коде соответствия.
        foreach (array_keys(OzonAccrualGroups::GROUP_OF_TYPE) as $feeTypeId) {
            self::assertStringStartsNotWith('Начисление типа', OzonFeeTypeNames::of($feeTypeId), "Тип {$feeTypeId}");
        }
    }

    public function testEveryGroupOfTheMappingHasALabel(): void
    {
        foreach (OzonAccrualGroups::GROUP_OF_TYPE as $group) {
            self::assertArrayHasKey($group, OzonAccrualGroups::LABELS);
        }
    }

    public function testRevenueIsSplitBySign(): void
    {
        // Продажи и возвраты — разные группы кабинета; у ленты это один
        // тип выручки, и различает их только знак (ADR-036).
        self::assertSame(OzonAccrualGroups::SALES, OzonAccrualGroups::of(OzonFeeTypeNames::REVENUE, false));
        self::assertSame(OzonAccrualGroups::RETURNS, OzonAccrualGroups::of(OzonFeeTypeNames::REVENUE, true));
    }

    public function testCommissionIsItsOwnGroup(): void
    {
        self::assertSame(OzonAccrualGroups::COMMISSION, OzonAccrualGroups::of(OzonFeeTypeNames::SALE_COMMISSION, false));
    }

    public function testPremiumSubscriptionIsAnOtherService(): void
    {
        // Подписка Premium впервые встретилась в сентябре 2026; группа —
        // по указанию владельца, как в кабинете.
        self::assertSame(OzonAccrualGroups::OTHER_SERVICES, OzonAccrualGroups::of(52, false));
    }

    public function testUnmappedTypeIsVisibleAsUngrouped(): void
    {
        // Куда кабинет относит тип, не встречавшийся в выгрузке, неизвестно.
        // Догадка сошлась бы в итоге и разошлась в группах.
        self::assertSame(OzonAccrualGroups::UNGROUPED, OzonAccrualGroups::of(118, false));
    }
}
