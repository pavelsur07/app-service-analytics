<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\NetForwardArtifactKind;

/** A selected case/component mask; outcome values never participate in selection. */
final readonly class NetQualityObservation
{
    /** @param list<string> $components
     * @param array<string, mixed> $payload */
    private function __construct(public string $companyId, public string $accountId, public string $orderNumber, public string $sku, public string $businessDate, public string $readAtUtc, public NetQualityArtifactRef $inputRef, public string $group, public array $components, public array $payload)
    {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        NetQualitySchema::keys($data, ['companyId', 'accountId', 'orderNumber', 'sku', 'businessDate', 'readAtUtc', 'input', 'group', 'components', 'payload']);
        if (!\is_array($data['input']) || !\is_array($data['payload'])) {
            throw new \InvalidArgumentException('Observation input or payload missing.');
        }
        $ref = NetQualityArtifactRef::fromArray($data['input']);
        $group = NetQualitySchema::string($data, 'group');
        if (NetForwardArtifactKind::Input !== $ref->kind || !\in_array($group, [...NetQualityProtocol::PARAMETERS['ageGroups'], 'overall'], true)) {
            throw new \InvalidArgumentException('Invalid observation group or input kind.');
        }
        NetQualitySchema::utc(NetQualitySchema::string($data, 'readAtUtc'));

        return new self(NetQualitySchema::uuid(NetQualitySchema::string($data, 'companyId')), NetQualitySchema::uuid(NetQualitySchema::string($data, 'accountId')), NetQualitySchema::string($data, 'orderNumber'), NetQualitySchema::string($data, 'sku'), NetQualitySchema::date(NetQualitySchema::string($data, 'businessDate')), NetQualitySchema::string($data, 'readAtUtc'), $ref, $group, NetQualitySchema::strings($data['components'], 20000), NetQualitySchema::rows([$data['payload']], 1)[0]);
    }

    public function caseKey(): string
    {
        return hash('sha256', json_encode([$this->companyId, $this->accountId, $this->orderNumber, $this->sku], \JSON_THROW_ON_ERROR));
    }

    public function withGroup(string $group): self
    {
        $data = $this->toArray();
        $data['group'] = $group;

        return self::fromArray($data);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['companyId' => $this->companyId, 'accountId' => $this->accountId, 'orderNumber' => $this->orderNumber, 'sku' => $this->sku, 'businessDate' => $this->businessDate, 'readAtUtc' => $this->readAtUtc, 'input' => $this->inputRef->toArray(), 'group' => $this->group, 'components' => $this->components, 'payload' => $this->payload];
    }

    public static function orderGroup(string $businessDate, \DateTimeImmutable $readAt): ?string
    {
        NetQualitySchema::date($businessDate);
        $date = $readAt->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d');
        if ($businessDate > $date) {
            return null;
        }
        $age = (new \DateTimeImmutable($businessDate))->diff(new \DateTimeImmutable($date))->days;

        return match (true) {
            $age >= 1 && $age <= 2 => 'order1_2',
            $age >= 3 && $age <= 5 => 'order3_5',
            $age >= 6 && $age <= 9 => 'order6_9',
            $age >= 10 && $age <= 15 => 'order10_15',
            default => null,
        };
    }

    public static function deliveryGroup(\DateTimeImmutable $origin, \DateTimeImmutable $readAt): ?string
    {
        if ($origin > $readAt) {
            return null;
        }
        $seconds = $readAt->getTimestamp() - $origin->getTimestamp();
        if ($readAt->format('u') < $origin->format('u')) {
            --$seconds;
        }
        $age = intdiv($seconds, 86400);

        return match (true) {
            $age < 7 => 'delivery0_6',
            $age < 30 => 'delivery7_29',
            $age < 60 => 'delivery30_59',
            default => null,
        };
    }
}
