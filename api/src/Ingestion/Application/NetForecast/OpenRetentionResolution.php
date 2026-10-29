<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

final readonly class OpenRetentionResolution
{
    /** @param list<OpenRetentionAttempt> $attempts */
    private function __construct(public ?OpenRetentionAssessment $selected, public array $attempts, public ?string $unavailableReason, public ?ScopedPreHandoverGrossRate $selectedPreGross, public ?string $preGrossUnavailableReason)
    {
    }

    public static function knownZero(OpenRetentionAssessment $selected): self
    {
        if (null === $selected->quantities || null !== $selected->unavailableReason || 'no_eligible_open' !== $selected->basis
            || 0 !== $selected->quantities->eligibleOpenQuantity || null !== $selected->grossRate || null !== $selected->returnCurve) {
            throw new \InvalidArgumentException('Known zero resolution requires an untrained zero assessment.');
        }

        return new self($selected, [], null, null, null);
    }

    /** @param list<OpenRetentionAttempt> $attempts */
    public static function available(OpenRetentionAssessment $selected, array $attempts, ?ScopedPreHandoverGrossRate $selectedPreGross = null, ?string $preGrossUnavailableReason = null): self
    {
        self::assertAttempts($attempts);
        if (null === $selected->quantities || null !== $selected->unavailableReason || !\in_array($selected->basis, ['sku', 'account'], true)
            || [] === $attempts || $attempts[array_key_last($attempts)]->assessment !== $selected) {
            throw new \InvalidArgumentException('Selected open retention must be its last successful attempt.');
        }
        foreach (\array_slice($attempts, 0, -1) as $attempt) {
            if (null !== $attempt->assessment->quantities || null === $attempt->assessment->unavailableReason) {
                throw new \InvalidArgumentException('Open retention cannot continue after a successful attempt.');
            }
        }

        self::assertPreGross($selectedPreGross, $preGrossUnavailableReason, $attempts);

        return new self($selected, $attempts, null, $selectedPreGross, $preGrossUnavailableReason);
    }

    /** @param list<OpenRetentionAttempt> $attempts */
    public static function unavailable(string $reason, array $attempts = [], ?ScopedPreHandoverGrossRate $selectedPreGross = null, ?string $preGrossUnavailableReason = null): self
    {
        self::assertAttempts($attempts);
        if ('' === trim($reason) || ([] !== $attempts && (2 !== \count($attempts) || 'open_retention_unavailable' !== $reason))) {
            throw new \InvalidArgumentException('Unavailable open retention requires a reason and both failed attempts.');
        }
        foreach ($attempts as $attempt) {
            if (null !== $attempt->assessment->quantities || null === $attempt->assessment->unavailableReason) {
                throw new \InvalidArgumentException('Unavailable open retention cannot contain a successful attempt.');
            }
        }

        self::assertPreGross($selectedPreGross, $preGrossUnavailableReason, $attempts);

        return new self(null, $attempts, $reason, $selectedPreGross, $preGrossUnavailableReason ?? ([] === $attempts ? $reason : null));
    }

    /** @param list<OpenRetentionAttempt> $attempts */
    private static function assertPreGross(?ScopedPreHandoverGrossRate $gross, ?string $reason, array $attempts): void
    {
        if (null === $gross) {
            return;
        }
        if (null !== $reason || null !== $gross->unavailableReason || null === $gross->source || null === $gross->probability || null === $gross->context) {
            throw new \InvalidArgumentException('Invalid selected PRE population.');
        }
        $first = null;
        foreach ($attempts as $attempt) {
            $rate = $attempt->assessment->grossRate;
            if ($rate instanceof ScopedPreHandoverGrossRate && null === $rate->unavailableReason && null !== $rate->probability) {
                $first = $rate;
                break;
            }
        }
        if ($first !== $gross || $gross->source->marketplaceSku !== $gross->marketplaceSku) {
            throw new \InvalidArgumentException('PRE population must be the first valid attempt.');
        }
        $batch = new NetTrainingBatch($gross->companyId, $gross->accountId, $gross->readAt, $gross->context, [$gross->source], []);
        $rebuilt = (new BuildPreHandoverGrossRateAction())->build($gross->companyId, $gross->accountId, $batch, $gross->marketplaceSku);
        if (null !== $rebuilt->unavailableReason || $rebuilt->probability?->numerator !== $gross->probability->numerator || $rebuilt->probability->denominator !== $gross->probability->denominator) {
            throw new \InvalidArgumentException('PRE population probability does not match its source.');
        }
    }

    /** @param list<OpenRetentionAttempt> $attempts */
    private static function assertAttempts(array $attempts): void
    {
        if (!array_is_list($attempts) || \count($attempts) > 2) {
            throw new \InvalidArgumentException('Open retention attempts must be a list of at most two scopes.');
        }
        foreach ($attempts as $index => $attempt) {
            if ((0 === $index) === (null === $attempt->marketplaceSku)) {
                throw new \InvalidArgumentException('Open retention attempts must use SKU then own account.');
            }
        }
    }
}
