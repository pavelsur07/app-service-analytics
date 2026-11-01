<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Contracts;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

final class ProductResearchOzonContractTest extends TestCase
{
    public function testSharedContractCorpus(): void
    {
        $directory = '/var/www/services/_contracts/product-research-ozon/v1/';
        $schemaText = file_get_contents($directory.'schema.json');
        $casesText = file_get_contents($directory.'examples.json');
        self::assertIsString($schemaText);
        self::assertIsString($casesText);
        /** @var \stdClass $schema */
        $schema = json_decode($schemaText, flags: \JSON_THROW_ON_ERROR);
        /** @var list<\stdClass> $cases */
        $cases = json_decode($casesText, flags: \JSON_THROW_ON_ERROR);
        self::assertGreaterThanOrEqual(20, \count($cases));
        foreach ($cases as $case) {
            self::assertIsString($case->definition);
            self::assertIsString($case->name);
            self::assertIsBool($case->valid);
            $schema->oneOf = [(object) ['$ref' => '#/$defs/'.$case->definition]];
            $result = new Validator()->validate($case->payload, $schema);
            self::assertSame($case->valid, $result->isValid(), $case->name);
        }
    }
}
