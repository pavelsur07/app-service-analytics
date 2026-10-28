<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\ValueObject;

use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

final readonly class NetForecastCaptureEpoch
{
    public const string CAPTURE_VERSION = 'accepted_state_v1';

    public function __construct(
        public string $id,
        public string $captureVersion,
        public \DateTimeImmutable $start,
        public ?\DateTimeImmutable $end,
        public string $cutoverRelease,
    ) {
        if (!Uuid::isValid($id) || !Uuid::fromString($id) instanceof UuidV7
            || self::CAPTURE_VERSION !== $captureVersion || '' === trim($cutoverRelease)
            || 0 !== $start->getOffset() || (null !== $end && (0 !== $end->getOffset() || $end <= $start))) {
            throw new \InvalidArgumentException('Invalid net forecast capture epoch.');
        }
    }

    public function contains(\DateTimeImmutable $at): bool
    {
        return $at >= $this->start && (null === $this->end || $at < $this->end);
    }
}
