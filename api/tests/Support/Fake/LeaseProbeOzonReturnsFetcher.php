<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Ingestion\Domain\OzonReturnsFetcher;
use Symfony\Component\Lock\LockFactory;

final class LeaseProbeOzonReturnsFetcher implements OzonReturnsFetcher
{
    public bool $overlapAcquired = false;

    private int $page = 0;

    /**
     * @param list<string> $responses
     */
    public function __construct(
        private readonly ExpiringLockStore $store,
        private readonly LockFactory $locks,
        private readonly string $resource,
        private array $responses,
    ) {
    }

    public function fetchPage(
        string $clientId,
        string $apiKey,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $lastId,
        int $limit = self::MAX_LIMIT,
    ): string {
        if ($this->page > 0) {
            $competitor = $this->locks->createLock($this->resource, 900);
            $this->overlapAcquired = $competitor->acquire();
            if ($this->overlapAcquired) {
                $competitor->release();
            }
        }

        $response = array_shift($this->responses);
        if (null === $response) {
            throw new \LogicException('No fake Ozon returns page left.');
        }

        ++$this->page;
        $this->store->expire($this->resource);

        return $response;
    }
}
