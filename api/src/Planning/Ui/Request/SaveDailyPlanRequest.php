<?php

declare(strict_types=1);

namespace App\Planning\Ui\Request;

use App\Planning\Domain\DailyPlan;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class SaveDailyPlanRequest
{
    private function __construct(
        #[Assert\NotNull]
        #[Assert\Type('integer')]
        #[Assert\Range(min: 0, max: DailyPlan::MAX_QUANTITY)]
        public mixed $quantity,
        #[Assert\NotNull]
        #[Assert\Type('integer')]
        #[Assert\Range(min: 0, max: DailyPlan::MAX_MUTABLE_VERSION)]
        public mixed $expectedVersion,
    ) {
    }

    public static function fromJson(string $body): self
    {
        $decoded = self::decode($body);
        self::requireExactFields($decoded, ['quantity', 'expectedVersion']);

        return new self($decoded['quantity'], $decoded['expectedVersion']);
    }

    public function validQuantity(): int
    {
        \assert(\is_int($this->quantity));

        return $this->quantity;
    }

    public function validExpectedVersion(): int
    {
        \assert(\is_int($this->expectedVersion));

        return $this->expectedVersion;
    }

    /** @return array<array-key, mixed> */
    private static function decode(string $body): array
    {
        try {
            $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('malformed_json');
        }
        if (!\is_array($decoded)) {
            throw new \InvalidArgumentException('malformed_json');
        }

        return $decoded;
    }

    /**
     * @param array<array-key, mixed> $decoded
     * @param list<string>            $expected
     */
    private static function requireExactFields(array $decoded, array $expected): void
    {
        $actual = array_keys($decoded);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new \InvalidArgumentException('request_fields_invalid');
        }
    }
}
