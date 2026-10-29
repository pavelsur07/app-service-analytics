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

    public function schemaVersion(): ?string
    {
        return match ($this) {
            self::Input, self::Truth => null,
            self::QualityProtocol => 'net_quality_protocol_v1',
            self::QualityInventory => 'net_quality_inventory_v1',
            self::QualitySelection => 'net_quality_selection_v1',
            self::QualityDataset => 'net_quality_dataset_v1',
            self::QualityReport => 'net_quality_report_v1',
        };
    }

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
