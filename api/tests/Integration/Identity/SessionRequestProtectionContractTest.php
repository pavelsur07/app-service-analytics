<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use Nelmio\ApiDocBundle\ApiDocGenerator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SessionRequestProtectionContractTest extends KernelTestCase
{
    public function testGeneratedContractRequiresProtectionOnSessionMutationsOnly(): void
    {
        self::bootKernel();
        $generator = self::getContainer()->get('nelmio_api_doc.generator.default');
        self::assertInstanceOf(ApiDocGenerator::class, $generator);
        $json = $generator->generate()->toJson();
        /** @var array{paths: array<string, array<string, array{parameters?: list<array{name?: string, required?: bool}>, responses: array<string, mixed>}>>} $document */
        $document = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        $login = $document['paths']['/api/auth/login']['post'];
        self::assertContains(['name' => 'X-Conwix-Request', 'required' => true], array_map(
            static fn (array $parameter): array => ['name' => $parameter['name'] ?? '', 'required' => $parameter['required'] ?? false],
            $login['parameters'] ?? [],
        ));
        self::assertArrayHasKey('415', $login['responses']);
        self::assertArrayHasKey('403', $login['responses']);
        foreach ($document['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if ('get' !== $method && !str_starts_with($path, '/api/extension/')) {
                    continue;
                }
                self::assertNotContains('X-Conwix-Request', array_column($operation['parameters'] ?? [], 'name'));
            }
        }
    }
}
