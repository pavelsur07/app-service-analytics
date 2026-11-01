<?php

declare(strict_types=1);

namespace App\Tests\Unit\ProductResearchOzon;

use App\ProductResearchOzon\Domain\PeriodRange;
use App\ProductResearchOzon\Domain\ResearchCalendar;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

final class ResearchContractTest extends TestCase
{
    public function testCalendarMonthsMatchPublishedServiceContract(): void
    {
        $raw = file_get_contents('/var/www/services/_contracts/product-research-ozon/v1/schema.json');
        self::assertIsString($raw);
        /** @var \stdClass $schema */
        $schema = json_decode($raw, flags: \JSON_THROW_ON_ERROR);
        $schema->oneOf = [(object) ['$ref' => '#/$defs/month']];
        $calendar = new ResearchCalendar();
        foreach ([[new PeriodRange('2024-02', '2024-03')], [new PeriodRange('2026-04', '2026-04')]] as $periods) {
            $preview = $calendar->preview($periods, true, new \DateTimeImmutable('2026-04-15T12:00:00Z'));
            foreach ($preview->months as $month) {
                $payload = (object) ['month' => $month->month, 'date_from' => $month->dateFrom, 'date_to' => $month->dateTo, 'days' => $month->days, 'partial' => $month->partial];
                self::assertTrue(new Validator()->validate($payload, $schema)->isValid());
            }
        }
    }
}
