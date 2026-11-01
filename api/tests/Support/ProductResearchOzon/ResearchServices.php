<?php

declare(strict_types=1);

namespace App\Tests\Support\ProductResearchOzon;

/** @phpstan-require-extends \Symfony\Bundle\FrameworkBundle\Test\KernelTestCase */
trait ResearchServices
{
    /** @template T of object
     * @param class-string<T> $class
     *
     * @return T
     */
    protected static function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
