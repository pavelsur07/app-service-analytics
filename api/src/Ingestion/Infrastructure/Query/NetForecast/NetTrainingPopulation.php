<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\NetForecast;

enum NetTrainingPopulation: string
{
    case Gross = 'gross';
    case Returns = 'returns';
}
