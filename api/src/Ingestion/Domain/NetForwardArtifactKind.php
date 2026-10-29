<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

enum NetForwardArtifactKind: string
{
    case Input = 'input';
    case Truth = 'truth';
    case QualityProtocol = 'quality-protocol';
    case QualityInventory = 'quality-inventory';
    case QualitySelection = 'quality-selection';
    case QualityDataset = 'quality-dataset';
    case QualityReport = 'quality-report';

    public function parentKind(): ?self
    {
        return match ($this) {
            self::Input, self::QualityProtocol => null,
            self::Truth => self::Input,
            self::QualityInventory => self::QualityProtocol,
            self::QualitySelection => self::QualityInventory,
            self::QualityDataset => self::QualitySelection,
            self::QualityReport => self::QualityDataset,
        };
    }
}
