<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * Глобальный проверенный справочник точных buyer reasons Ozon.
 * Строки добавляются только после research, неизвестное не угадывается.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ozon_return_reason_classification')]
class OzonReturnReasonClassification
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private readonly string $returnType;

    #[ORM\Id]
    #[ORM\Column(type: 'text')]
    private readonly string $returnReasonName;

    #[ORM\Column(length: 32, enumType: OzonReturnEventStage::class)]
    private readonly OzonReturnEventStage $eventStage;

    #[ORM\Column(type: 'date_immutable')]
    private readonly \DateTimeImmutable $verifiedAt;

    public function __construct(
        string $returnType,
        string $returnReasonName,
        OzonReturnEventStage $eventStage,
        \DateTimeImmutable $verifiedAt,
    ) {
        $this->returnType = $returnType;
        $this->returnReasonName = $returnReasonName;
        $this->eventStage = $eventStage;
        $this->verifiedAt = $verifiedAt;
    }

    public function returnType(): string
    {
        return $this->returnType;
    }

    public function returnReasonName(): string
    {
        return $this->returnReasonName;
    }

    public function eventStage(): OzonReturnEventStage
    {
        return $this->eventStage;
    }

    public function verifiedAt(): \DateTimeImmutable
    {
        return $this->verifiedAt;
    }
}
