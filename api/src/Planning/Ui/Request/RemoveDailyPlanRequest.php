<?php

declare(strict_types=1);

namespace App\Planning\Ui\Request;

use App\Planning\Domain\DailyPlan;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class RemoveDailyPlanRequest
{
    private function __construct(
        #[Assert\NotNull]
        #[Assert\Type('integer')]
        #[Assert\Range(min: 0, max: DailyPlan::MAX_MUTABLE_VERSION)]
        public mixed $expectedVersion,
    ) {
    }

    public static function fromJson(string $body): self
    {
        try {
            $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('malformed_json');
        }
        if (!\is_array($decoded) || ['expectedVersion'] !== array_keys($decoded)) {
            throw new \InvalidArgumentException('request_fields_invalid');
        }

        return new self($decoded['expectedVersion']);
    }

    public function validExpectedVersion(): int
    {
        \assert(\is_int($this->expectedVersion));

        return $this->expectedVersion;
    }
}
