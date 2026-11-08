<?php

declare(strict_types=1);

namespace App\Advertising\Application;

use OpenApi\Attributes as OA;

final readonly class ObservationPolicyResponse
{
    /**
     * @param array{amountMinor: int, currency: string}                                                                                                                                                                                           $dailyLimit
     * @param array{appliedVersion: int, day: string, spend: array{amountMinor: int, currency: string}|null, difference: array{amountMinor: int, currency: string}|null, action: string, reason: string, latched: bool, evaluatedAt: string}|null $observation
     */
    public function __construct(
        #[OA\Property(format: 'uuid')] public string $id,
        #[OA\Property(format: 'uuid')] public string $accountId,
        public string $campaignId,
        public int $version,
        #[OA\Property(enum: ['observe'])] public string $mode,
        public bool $enabled,
        #[OA\Property(type: 'object', properties: [new OA\Property(property: 'amountMinor', type: 'integer', minimum: 1, maximum: 9007199254740991), new OA\Property(property: 'currency', type: 'string', enum: ['RUB'])])] public array $dailyLimit,
        public string $start,
        public string $stop,
        #[OA\Property(enum: ['Europe/Moscow'])] public string $timezone,
        public int $pollSeconds,
        #[OA\Property(enum: ['saved', 'applied'])] public string $deliveryStatus,
        #[OA\Property(type: 'object', nullable: true, properties: [new OA\Property(property: 'appliedVersion', type: 'integer'), new OA\Property(property: 'day', type: 'string', format: 'date'), new OA\Property(property: 'spend', type: 'object', nullable: true, properties: [new OA\Property(property: 'amountMinor', type: 'integer'), new OA\Property(property: 'currency', type: 'string')]), new OA\Property(property: 'difference', type: 'object', nullable: true, properties: [new OA\Property(property: 'amountMinor', type: 'integer'), new OA\Property(property: 'currency', type: 'string')]), new OA\Property(property: 'action', type: 'string', enum: ['would_run', 'would_stop', 'blocked']), new OA\Property(property: 'reason', type: 'string'), new OA\Property(property: 'latched', type: 'boolean'), new OA\Property(property: 'evaluatedAt', type: 'string', format: 'date-time')])] public ?array $observation,
        public bool $sourceAgeKnown = false,
        public bool $budgetProtectionActive = false,
    ) {
    }
}
