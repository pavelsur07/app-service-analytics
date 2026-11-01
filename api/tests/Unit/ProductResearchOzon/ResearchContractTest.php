<?php

declare(strict_types=1);

namespace App\Tests\Unit\ProductResearchOzon;

use App\ProductResearchOzon\Domain\PeriodRange;
use App\ProductResearchOzon\Domain\ResearchCalendar;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

final class ResearchContractTest extends TestCase
{
    public function testSerializedMonthMatchesPublicOpenApi(): void
    {
        $raw = file_get_contents('/var/www/packages/api-schema/openapi.json');
        self::assertIsString($raw);
        /** @var \stdClass $schema */
        $schema = json_decode($raw, flags: \JSON_THROW_ON_ERROR);
        $schema->{'$schema'} = 'https://json-schema.org/draft/2020-12/schema';
        $schema->{'$ref'} = '#/components/schemas/MonthResponse';
        $response = new \App\ProductResearchOzon\Ui\Response\MonthResponse(new \App\ProductResearchOzon\Domain\MonthSlice('2026-01', '2026-01-01', '2026-01-31', 31, false));
        $payload = json_decode(json_encode($response, \JSON_THROW_ON_ERROR), flags: \JSON_THROW_ON_ERROR);
        self::assertTrue(new Validator()->validate($payload, $schema)->isValid(), 'Serialized month must satisfy the actual public schema, not just generator freshness');
    }

    public function testSerializedCategorySearchMatchesPublicOpenApi(): void
    {
        $raw = file_get_contents('/var/www/packages/api-schema/openapi.json');
        self::assertIsString($raw);
        /** @var \stdClass $schema */
        $schema = json_decode($raw, flags: \JSON_THROW_ON_ERROR);
        $schema->{'$schema'} = 'https://json-schema.org/draft/2020-12/schema';
        $schema->{'$ref'} = '#/components/schemas/ResearchCategoriesResponse';
        $result = new \App\ProductResearchOzon\Domain\ValueObject\CatalogSearchResult(new \App\ProductResearchOzon\Domain\ValueObject\CatalogMetadata(7, new \DateTimeImmutable('2026-11-01T10:00:00.123456Z')), ['Аксессуары/Хиджабы']);
        $response = new \App\ProductResearchOzon\Ui\Response\ResearchCategoriesResponse($result);
        $payload = json_decode(json_encode($response, \JSON_THROW_ON_ERROR), flags: \JSON_THROW_ON_ERROR);
        self::assertTrue(new Validator()->validate($payload, $schema)->isValid());
    }

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
