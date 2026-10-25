<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Штатный API-клиент; негативные CSRF-тесты используют исходный WebTestCase. */
abstract class SessionWebTestCase extends WebTestCase
{
    /**
     * @param array<array-key, mixed> $options
     * @param array<array-key, mixed> $server
     */
    protected static function createClient(array $options = [], array $server = []): KernelBrowser
    {
        return parent::createClient($options, ['HTTP_X_CONWIX_REQUEST' => '1', ...$server]);
    }
}
