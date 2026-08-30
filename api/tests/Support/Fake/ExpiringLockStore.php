<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;

/** Test store with an explicitly advanced lease, without sleeping. */
final class ExpiringLockStore implements PersistingStoreInterface
{
    /** @var array<string, string> */
    private array $owners = [];

    /** @var array<string, true> */
    private array $expired = [];

    public function save(Key $key): void
    {
        $resource = (string) $key;
        $token = $this->token($key);
        $owner = $this->owners[$resource] ?? null;
        if (null !== $owner && $owner !== $token && !isset($this->expired[$resource])) {
            throw new LockConflictedException();
        }

        $this->owners[$resource] = $token;
        unset($this->expired[$resource]);
    }

    public function delete(Key $key): void
    {
        $resource = (string) $key;
        if (($this->owners[$resource] ?? null) === $this->token($key)) {
            unset($this->owners[$resource], $this->expired[$resource]);
        }
    }

    public function exists(Key $key): bool
    {
        $resource = (string) $key;

        return !isset($this->expired[$resource])
            && ($this->owners[$resource] ?? null) === $this->token($key);
    }

    public function putOffExpiration(Key $key, float $ttl): void
    {
        $resource = (string) $key;
        if (($this->owners[$resource] ?? null) !== $this->token($key)) {
            throw new LockConflictedException();
        }

        unset($this->expired[$resource]);
    }

    public function expire(string $resource): void
    {
        if (isset($this->owners[$resource])) {
            $this->expired[$resource] = true;
        }
    }

    private function token(Key $key): string
    {
        if (!$key->hasState(self::class)) {
            $key->setState(self::class, bin2hex(random_bytes(16)));
        }

        $token = $key->getState(self::class);
        \assert(\is_string($token));

        return $token;
    }
}
