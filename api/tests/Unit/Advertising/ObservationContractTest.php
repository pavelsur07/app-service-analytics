<?php

declare(strict_types=1);

namespace App\Tests\Unit\Advertising;

use App\Advertising\Application\ObservationInput;
use App\Advertising\Ui\Request\PolicyRequest;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

final class ObservationContractTest extends TestCase
{
    public function testSharedCorpus(): void
    {
        $root = '/var/www/services/_contracts/ozon-ads-observe/v1/';
        $schemaRaw = file_get_contents($root.'schema.json');
        $casesRaw = file_get_contents($root.'examples.json');
        self::assertIsString($schemaRaw);
        self::assertIsString($casesRaw);
        /** @var \stdClass $schema */ $schema = json_decode($schemaRaw, flags: \JSON_THROW_ON_ERROR);
        /** @var list<\stdClass> $cases */ $cases = json_decode($casesRaw, flags: \JSON_THROW_ON_ERROR);
        foreach ($cases as $case) {
            self::assertIsString($case->definition);
            self::assertIsBool($case->valid);
            self::assertIsString($case->name);
            $schema->oneOf = [(object) ['$ref' => '#/$defs/'.$case->definition]];
            self::assertSame($case->valid, new Validator()->validate($case->payload, $schema)->isValid(), $case->name);
        }
    }

    public function testProjectionRestoresMoney(): void
    {
        $raw = file_get_contents('/var/www/services/_contracts/ozon-ads-observe/v1/examples.json');
        self::assertIsString($raw);
        /** @var list<array{payload: array<string,mixed>}> $cases */ $cases = json_decode($raw, true, flags: \JSON_THROW_ON_ERROR);
        $input = ObservationInput::fromArray($cases[1]['payload'], new \DateTimeImmutable('2026-11-08T10:00:10Z'));
        self::assertNotNull($input->spend);
        self::assertSame(103, $input->spend->minorAmount());
        self::assertSame('RUB', $input->spend->currency());
    }

    public function testPolicyRejectsFloatingPointMoney(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PolicyRequest::input(['dailyLimit' => ['amountMinor' => 1.1, 'currency' => 'RUB'], 'start' => '09:00', 'stop' => '21:00', 'pollSeconds' => 600, 'enabled' => true], true);
    }

    public function testRuntimeParserRejectsInvalidContractCorpus(): void
    {
        $raw = file_get_contents('/var/www/services/_contracts/ozon-ads-observe/v1/examples.json');
        self::assertIsString($raw);
        /** @var list<array{definition:string,valid:bool,payload:array<string,mixed>}> $cases */ $cases = json_decode($raw, true, flags: \JSON_THROW_ON_ERROR);
        foreach ($cases as $case) {
            if ($case['valid'] || 'Observation' !== $case['definition']) {
                continue;
            }
            $rejected = false;
            try {
                ObservationInput::fromArray($case['payload'], new \DateTimeImmutable('2026-11-08T10:00:10Z'));
            } catch (\InvalidArgumentException) {
                $rejected = true;
            }
            self::assertTrue($rejected);
        }
    }
}
