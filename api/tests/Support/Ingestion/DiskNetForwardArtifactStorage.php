<?php

declare(strict_types=1);

namespace App\Tests\Support\Ingestion;

use App\Ingestion\Domain\NetForwardArtifactKey;
use App\Ingestion\Domain\NetForwardArtifactMissing;
use App\Ingestion\Domain\NetForwardArtifactStorage;

/** Synthetic local profile adapter. Real hash verification stays in the production reader. */
final class DiskNetForwardArtifactStorage implements NetForwardArtifactStorage
{
    private readonly string $directory;
    public int $gets = 0;
    public int $bytes = 0;

    public function __construct()
    {
        $path = sys_get_temp_dir().'/net-quality-profile-'.bin2hex(random_bytes(8));
        if (!mkdir($path, 0700)) {
            throw new \RuntimeException('Cannot create profile storage.');
        }
        $this->directory = $path;
    }

    public function putContent(string $companyId, string $accountId, NetForwardArtifactKey $key, string $body): void
    {
        $key->assertScope($companyId, $accountId);
        if (hash('sha256', $body) !== $key->sha256) {
            throw new \UnexpectedValueException('Profile object hash mismatch.');
        }
        $path = $this->directory.'/'.hash('sha256', $key->toString());
        if (file_put_contents($path, $body) !== \strlen($body)) {
            throw new \RuntimeException('Cannot write profile object.');
        }
        chmod($path, 0600);
    }

    public function get(string $companyId, string $accountId, NetForwardArtifactKey $key, int $maxBytes): string
    {
        $key->assertScope($companyId, $accountId);
        if ($maxBytes < 1 || $maxBytes > 1048576) {
            throw new \InvalidArgumentException('Invalid profile object read limit.');
        }
        $path = $this->directory.'/'.hash('sha256', $key->toString());
        if (!is_file($path)) {
            throw new NetForwardArtifactMissing();
        }
        $body = file_get_contents($path, length: $maxBytes + 1);
        if (false === $body || \strlen($body) > $maxBytes) {
            throw new \OverflowException('Profile read exceeds object cap.');
        }
        ++$this->gets;
        $this->bytes += \strlen($body);

        return $body;
    }

    public function __destruct()
    {
        foreach ((false === ($paths = glob($this->directory.'/*')) ? [] : $paths) as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }
}
