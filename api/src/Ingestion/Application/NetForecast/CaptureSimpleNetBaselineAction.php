<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\ValueObject\NetForecastQuantities;
use App\Ingestion\Domain\ValueObject\NetForecastRevenue;
use App\Ingestion\Domain\ValueObject\SimpleMatureReturnBaseline;
use App\Ingestion\Infrastructure\Query\NetForecast\NetForwardBaselineRate;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetBatch;

final readonly class CaptureSimpleNetBaselineAction
{
    public function __construct(private NetForwardInputCodec $codec)
    {
    }

    /** @param list<NetForwardBaselineRate> $rates */
    public function __invoke(NetTargetBatch $targets, NetForecastBatch $forecast, array $rates, NetForwardSpool $spool): void
    {
        $byScope = [];
        foreach ($rates as $rate) {
            $key = $rate->marketplaceSku ?? '';
            $byScope[$key] = $rate;
        }
        $modelParts = [];
        foreach ($forecast->skus as $sku) {
            foreach ($sku->days as $day) {
                foreach ($day->groups as $group) {
                    foreach ($group->parts as $part) {
                        $modelParts[$part->sourceRowId] = $part;
                    }
                }
            }
        }
        foreach ($targets->skus as $sku) {
            $attempts = [];
            $selected = null;
            foreach ([$sku->marketplaceSku, ''] as $scope) {
                $rate = $byScope[$scope] ?? null;
                $gross = $rate?->grossQuantity;
                $returned = $rate?->returnedQuantity;
                $postings = null === $rate ? 0 : $rate->postings;
                $eligible = null !== $rate && $rate->inputComplete && null !== $gross && null !== $returned && \strlen($gross) >= 3 && $postings >= 30;
                $attempts[] = ['scope' => '' === $scope ? 'account' : 'sku', 'gross' => $gross, 'returned' => $returned, 'postings' => $postings, 'reason' => $eligible ? null : 'insufficient_sample'];
                if ($eligible) {
                    $selected = ['gross' => $gross, 'returned' => $returned, 'scope' => '' === $scope ? 'account' : 'sku'];
                    break;
                }
            }
            foreach ($sku->days as $day) {
                foreach ($day->groups as $group) {
                    foreach ($group->parts as $part) {
                        $spool->guard();
                        $quantity = null;
                        $reason = $part->unavailableReason;
                        $model = $modelParts[$part->sourceRowId] ?? null;
                        if (null === $reason && true === $part->inputComplete && null !== $part->currentD && null !== $part->eligibleOpenPre && null !== $part->eligibleOpenPost && 0 === ($part->unknownQuantity ?? 1)) {
                            $quantity = NetForecastQuantities::zero();
                            $age = null === $part->deliveryOrigin ? null : intdiv($targets->context->readAt->getTimestamp() - $part->deliveryOrigin->getTimestamp(), 86400);
                            if ($part->currentD > 0) {
                                if (null !== $age && $age >= 60) {
                                    $quantity = NetForecastQuantities::fromComponents((string) $part->currentD, '0', '0', '1', '0', '1');
                                } elseif (null !== $age && null !== $selected && null !== $selected['returned'] && null !== $selected['gross']) {
                                    $quantity = SimpleMatureReturnBaseline::delivered($part->currentD, $age, $selected['returned'], $selected['gross']);
                                } else {
                                    $reason = 'insufficient_sample';
                                }
                            }
                            foreach ([[$part->eligibleOpenPre, $model?->openPre], [$part->eligibleOpenPost, $model?->openPost]] as [$units, $gross]) {
                                if ($units > 0) {
                                    if (null === $selected || null === $selected['returned'] || null === $selected['gross'] || null === $gross?->probabilityNumerator || null === $gross->probabilityDenominator) {
                                        $reason = 'insufficient_sample';
                                    } else {
                                        $quantity = $quantity->plus(SimpleMatureReturnBaseline::open($units, $gross->probabilityNumerator, $gross->probabilityDenominator, $selected['returned'], $selected['gross']));
                                    }
                                }
                            }
                        } else {
                            $reason ??= 'source_history_unavailable';
                        }
                        if (null !== $reason) {
                            $quantity = null;
                        }
                        $revenue = null === $quantity ? null : NetForecastRevenue::fromSource($part->trace->amountMinor, $part->trace->currency, $quantity);
                        [$money, $moneyReason] = null === $revenue ? [null, null] : $revenue->toMoneyOutcome();
                        $spool->append('simple_baseline_part', ['sourceRowId' => $part->sourceRowId, 'sku' => $sku->marketplaceSku, 'day' => $day->businessDate, 'orderNumber' => $group->orderNumber, 'version' => 'simple_mature_return_linear_h60_v1', 'selected' => $selected, 'attempts' => $attempts, 'quantities' => $this->codec->pack($quantity), 'reason' => $reason, 'unitPriceMinor' => $part->trace->amountMinor, 'currency' => $part->trace->currency, 'money' => $this->codec->pack($money), 'moneyReason' => $moneyReason]);
                    }
                }
            }
        }
    }
}
