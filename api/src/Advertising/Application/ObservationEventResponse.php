<?php

declare(strict_types=1);

namespace App\Advertising\Application;

use OpenApi\Attributes as OA;

#[OA\Schema(required: ['id', 'policyId', 'accountId', 'campaignId', 'version', 'kind', 'actorId', 'action', 'reason', 'details', 'occurredAt', 'dailyLimit', 'spend', 'difference'])]
final readonly class ObservationEventResponse
{
    /**
     * @param array<string, int|string|bool|null>            $details
     * @param array{amountMinor: int, currency: string}|null $dailyLimit
     * @param array{amountMinor: int, currency: string}|null $spend
     * @param array{amountMinor: int, currency: string}|null $difference
     */
    public function __construct(
        public string $id,
        public string $policyId,
        public string $accountId,
        public string $campaignId,
        public int $version,
        #[OA\Property(enum: ['policy_created', 'policy_updated', 'observation'])]
        public string $kind,
        #[OA\Property(type: 'string', nullable: true)]
        public ?string $actorId,
        #[OA\Property(enum: ['saved', 'would_run', 'would_stop', 'blocked'])]
        public string $action,
        public string $reason,
        #[OA\Property(type: 'object', properties: [
            new OA\Property(property: 'start', type: 'string'),
            new OA\Property(property: 'stop', type: 'string'),
            new OA\Property(property: 'pollSeconds', type: 'integer'),
            new OA\Property(property: 'enabled', type: 'boolean'),
            new OA\Property(property: 'day', type: 'string'),
            new OA\Property(property: 'latched', type: 'boolean'),
        ], additionalProperties: false)]
        public array $details,
        #[OA\Property(format: 'date-time')]
        public string $occurredAt,
        #[OA\Property(type: 'object', nullable: true, required: ['amountMinor', 'currency'], properties: [new OA\Property(property: 'amountMinor', type: 'integer'), new OA\Property(property: 'currency', type: 'string', enum: ['RUB'])])]
        public ?array $dailyLimit,
        #[OA\Property(type: 'object', nullable: true, required: ['amountMinor', 'currency'], properties: [new OA\Property(property: 'amountMinor', type: 'integer'), new OA\Property(property: 'currency', type: 'string', enum: ['RUB'])])]
        public ?array $spend,
        #[OA\Property(type: 'object', nullable: true, required: ['amountMinor', 'currency'], properties: [new OA\Property(property: 'amountMinor', type: 'integer'), new OA\Property(property: 'currency', type: 'string', enum: ['RUB'])])]
        public ?array $difference,
    ) {
    }
}
