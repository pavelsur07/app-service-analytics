<?php

declare(strict_types=1);

namespace App\PriceMonitoring\Application;

use App\PriceMonitoring\Domain\PriceObservation;
use App\PriceMonitoring\Domain\PriceObservationRepository;
use App\PriceMonitoring\Domain\RecordObservationOutcome;
use App\PriceMonitoring\Domain\TrackedSkuRepository;
use App\Shared\Domain\ValueObject\Money;
use Symfony\Component\Uid\Uuid;

/**
 * Приём снимка цены от расширения (ADR-014).
 *
 * Наблюдения принимаются только по отслеживаемому артикулу: иначе
 * эндпоинт превращается в место для произвольной записи чем попало.
 * Та же строка отслеживания сообщает и кабинет — клиент его не знает
 * и знать не должен.
 *
 * Между чтением строки отслеживания и вставкой продавец теоретически
 * успевает нажать «Остановить». Тогда запишется одно лишнее наблюдение
 * по артикулу, который только что сняли с отслеживания, — и это ровно
 * то, что и произошло на самом деле: фоновое окно уже было открыто,
 * цену уже прочитали. Замок здесь сторожил бы событие, у которого нет
 * последствий.
 */
final class RecordPriceObservationAction
{
    public function __construct(
        private readonly TrackedSkuRepository $trackedSkus,
        private readonly PriceObservationRepository $observations,
    ) {
    }

    public function __invoke(
        string $companyId,
        string $marketplaceSku,
        \DateTimeImmutable $observedAt,
        Money $displayedPrice,
        Money $sellerPrice,
        string $actorUserId,
        string $extensionVersion,
        \DateTimeImmutable $receivedAt,
    ): RecordObservationOutcome {
        $accountId = $this->trackedSkus->activeAccountIdFor($companyId, $marketplaceSku);
        if (null === $accountId) {
            return RecordObservationOutcome::NotTracked;
        }

        $recorded = $this->observations->record(PriceObservation::captured(
            companyId: Uuid::fromString($companyId),
            marketplaceAccountId: $accountId,
            marketplaceSku: $marketplaceSku,
            observedAt: $observedAt,
            displayedPrice: $displayedPrice,
            sellerPrice: $sellerPrice,
            capturedByUserId: Uuid::fromString($actorUserId),
            extensionVersion: $extensionVersion,
            receivedAt: $receivedAt,
        ));

        return $recorded ? RecordObservationOutcome::Recorded : RecordObservationOutcome::Duplicate;
    }
}
