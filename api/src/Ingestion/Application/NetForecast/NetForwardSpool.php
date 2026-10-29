<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

/** Private bounded local bytes, never a durable publication. Files die with the owner. */
final class NetForwardSpool
{
    /** @var list<array{type: string, ordinal: int, rows: int, bytes: int, sha256: string}> */
    private array $descriptors = [];
    /** @var list<string> */
    private array $paths = [];
    /** @var list<array<array-key, mixed>> */
    private array $buffer = [];
    private string $type = '';
    private int $bufferBytes = 0;
    private int $totalBytes = 0;
    public readonly int $deadlineNs;

    public function __construct(private readonly NetForwardManifestCodec $codec, ?int $deadlineNs = null)
    {
        $this->deadlineNs = min($deadlineNs ?? \PHP_INT_MAX, (int) (hrtime(true) + 30000000000));
    }

    public function guard(): void
    {
        if (hrtime(true) >= $this->deadlineNs || memory_get_peak_usage(true) > 100663296 || memory_get_usage(true) + 8388608 > 100663296) {
            throw new \OverflowException('Artifact capture resource budget exceeded.');
        }
    }

    /** @param array<array-key, mixed> $row */
    public function append(string $type, array $row): void
    {
        $this->guard();
        if (1 !== preg_match('/^[a-z_]{1,40}$/D', $type)) {
            throw new \InvalidArgumentException('Invalid artifact segment type.');
        }
        $size = \strlen($this->codec->encode($row));
        if ($size > 1048000) {
            throw new \OverflowException('Artifact row byte limit exceeded.');
        }
        if ([] !== $this->buffer && ($type !== $this->type || \count($this->buffer) >= 500 || $this->bufferBytes + $size > 1048000)) {
            $this->flush();
        }
        $this->type = $type;
        $this->buffer[] = $row;
        $this->bufferBytes += $size;
    }

    /** @return list<array{type: string, ordinal: int, rows: int, bytes: int, sha256: string}> */
    public function descriptors(): array
    {
        $this->flush();

        return $this->descriptors;
    }

    public function body(int $ordinal): string
    {
        $this->guard();
        $path = $this->paths[$ordinal] ?? throw new \OutOfBoundsException('Unknown chunk ordinal.');
        $body = file_get_contents($path, length: 1048577);
        $descriptor = $this->descriptors[$ordinal];
        if (false === $body || \strlen($body) !== $descriptor['bytes'] || hash('sha256', $body) !== $descriptor['sha256']) {
            throw new \UnexpectedValueException('Spool integrity mismatch.');
        }

        return $body;
    }

    public function __destruct()
    {
        foreach ($this->paths as $path) {
            unlink($path);
        }
    }

    private function flush(): void
    {
        if ([] === $this->buffer) {
            return;
        }
        $this->guard();
        if (\count($this->descriptors) >= 128) {
            throw new \OverflowException('Artifact chunk limit exceeded.');
        }
        $body = $this->codec->encode(['type' => $this->type, 'rows' => $this->buffer]);
        $size = \strlen($body);
        if ($this->totalBytes + $size > 134217728) {
            throw new \OverflowException('Artifact total byte limit exceeded.');
        }
        $path = tempnam(sys_get_temp_dir(), 'net-forward-');
        if (false === $path) {
            throw new \RuntimeException('Cannot create private artifact spool.');
        }
        $this->paths[] = $path;
        chmod($path, 0600);
        if (file_put_contents($path, $body) !== $size) {
            throw new \RuntimeException('Cannot write artifact spool.');
        }
        $this->descriptors[] = ['type' => $this->type, 'ordinal' => \count($this->descriptors), 'rows' => \count($this->buffer), 'bytes' => $size, 'sha256' => hash('sha256', $body)];
        $this->totalBytes += $size;
        $this->buffer = [];
        $this->bufferBytes = 0;
    }
}
