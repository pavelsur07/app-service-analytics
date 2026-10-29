<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

enum NetForwardArtifactKind: string
{
    case Input = 'input';
    case Truth = 'truth';
}
