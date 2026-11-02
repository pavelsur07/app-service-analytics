<?php

declare(strict_types=1);

namespace App\Tests\Unit\ProductResearchOzon;

use App\ProductResearchOzon\Infrastructure\Config\ResearchRollout;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ResearchRolloutTest extends TestCase
{
    public function testOnlyCanonicalExplicitCompaniesAreAllowed(): void
    {
        $company = Uuid::v7()->toRfc4122();
        $other = Uuid::v7()->toRfc4122();
        self::assertTrue((new ResearchRollout($company.','.$other))->allows($company));
        self::assertFalse((new ResearchRollout($company))->allows($other));
        foreach (['', '*', $company.',invalid', $company.','.$company, strtoupper($company), str_repeat('x', 100000)] as $value) {
            self::assertFalse((new ResearchRollout($value))->allows($company));
        }
    }
}
