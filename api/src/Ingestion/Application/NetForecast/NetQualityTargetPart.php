<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetPartRow;

/** Only the source values needed for selected-case evaluation; witness graphs stay sealed. */
final readonly class NetQualityTargetPart
{
    public function __construct(public string $sourceRowId, public int $rawQuantity, public ?int $currentD, public ?int $openPre, public ?int $openPost, public ?int $unknown, public ?\DateTimeImmutable $origin, public ?string $price, public ?string $currency, public bool $inputComplete, public ?string $reason, public ?string $firstRevisionId, public ?string $latestRevisionId)
    {
    }

    public static function fromSource(NetTargetPartRow $part, \DateTimeImmutable $at): self
    {
        $sum = 0;
        foreach ([$part->currentD, $part->knownR, $part->t1, $part->t2, $part->p, $part->eligibleOpenPre, $part->eligibleOpenPost, $part->unknownQuantity] as $value) {
            if (null !== $value) {
                if ($value < 0 || $value > $part->rawQuantity - $sum) {
                    throw new \UnexpectedValueException('Source quantity partition exceeds ordered units.');
                }
                $sum += $value;
            }
        }
        if ($part->rawQuantity < 0 || $part->trace->sourceRowId !== $part->sourceRowId || $part->trace->initialCapturedAt > $at || $part->trace->latestCapturedAt > $at) {
            throw new \UnexpectedValueException('Source identity or as-of invariant failed.');
        }

        return new self($part->sourceRowId, $part->rawQuantity, $part->currentD, $part->eligibleOpenPre, $part->eligibleOpenPost, $part->unknownQuantity, $part->deliveryOrigin, $part->trace->amountMinor, $part->trace->currency, $part->inputComplete, $part->unavailableReason, $part->trace->firstRevisionId, $part->trace->latestRevisionId);
    }
}
