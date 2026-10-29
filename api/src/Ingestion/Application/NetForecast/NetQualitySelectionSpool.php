<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

/** Small in-memory index, bounded private bytes; selected payloads are never all hydrated. */
final class NetQualitySelectionSpool
{
    private readonly string $path;
    private readonly \SplFileObject $file;
    private int $bytes = 0;

    public function __construct(private readonly NetQualityBudget $budget, private readonly NetForwardManifestCodec $codec = new NetForwardManifestCodec())
    {
        $path = tempnam(sys_get_temp_dir(), 'net-quality-');
        if (false === $path) {
            throw new \RuntimeException('Cannot create private selection spool.');
        }
        chmod($path, 0600);
        $this->path = $path;
        $this->file = new \SplFileObject($path, 'w+b');
    }

    /** @return array{int, int} */
    public function append(NetQualityObservation $observation): array
    {
        $this->budget->guard();
        $body = $this->codec->encode($observation->toArray());
        $length = \strlen($body);
        $this->budget->spoolBytes($length);
        $this->bytes += $length;
        $this->file->fseek(0, \SEEK_END);
        $offset = $this->file->ftell();
        if (false === $offset || $this->file->fwrite($body) !== $length) {
            throw new \RuntimeException('Cannot write the private selection spool.');
        }

        return [$offset, $length];
    }

    public function read(int $offset, int $length): NetQualityObservation
    {
        $this->budget->guard();
        if ($length < 1 || $length > NetForwardManifestCodec::MAX_BYTES || 0 !== $this->file->fseek($offset)) {
            throw new \UnexpectedValueException('Invalid selection spool offset.');
        }
        $body = $this->file->fread($length);
        if (false === $body || \strlen($body) !== $length) {
            throw new \UnexpectedValueException('Truncated selection spool.');
        }
        $data = $this->codec->decode($body);

        return NetQualityObservation::fromArray(NetQualitySchema::rows([$data], 1)[0]);
    }

    public function __destruct()
    {
        unlink($this->path);
        $this->budget->spoolBytes(-$this->bytes);
    }
}
