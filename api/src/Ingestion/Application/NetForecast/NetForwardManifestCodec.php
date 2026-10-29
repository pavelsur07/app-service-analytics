<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

/** Canonical, versioned plain JSON. Integer values stay integers or exact decimal strings. */
final readonly class NetForwardManifestCodec
{
    public const string VERSION = 'net_forward_manifest_v1';
    public const int MAX_BYTES = 1048576;

    /** @param array<array-key, mixed> $payload */
    public function encode(array $payload): string
    {
        $canonical = self::canonical($payload);
        $raw = json_encode($canonical, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES, 48);
        $bytes = json_encode(['version' => self::VERSION, 'payloadSha256' => hash('sha256', $raw), 'payload' => $canonical], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES, 48);
        if (\strlen($bytes) > self::MAX_BYTES) {
            throw new \OverflowException('Artifact object byte limit exceeded.');
        }

        return $bytes;
    }

    /** @return array<array-key, mixed> */
    public function decode(string $bytes): array
    {
        if (\strlen($bytes) > self::MAX_BYTES) {
            throw new \OverflowException('Artifact object byte limit exceeded.');
        }
        try {
            $data = json_decode($bytes, true, 48, \JSON_THROW_ON_ERROR);
            if (!\is_array($data) || array_keys($data) !== ['version', 'payloadSha256', 'payload'] || self::VERSION !== $data['version'] || !\is_string($data['payloadSha256']) || !\is_array($data['payload']) || $this->encode($data['payload']) !== $bytes) {
                throw new \UnexpectedValueException('Invalid artifact envelope.');
            }
        } catch (\JsonException|\InvalidArgumentException $error) {
            throw new \UnexpectedValueException('Invalid artifact encoding.', 0, $error);
        }

        return $data['payload'];
    }

    private static function canonical(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 40) {
            throw new \InvalidArgumentException('Artifact depth exceeded.');
        }
        if (\is_array($value)) {
            if (\count($value) > 20000) {
                throw new \InvalidArgumentException('Artifact list limit exceeded.');
            }
            if (!array_is_list($value)) {
                ksort($value, \SORT_STRING);
            }
            foreach ($value as &$item) {
                $item = self::canonical($item, $depth + 1);
            }
            unset($item);

            return $value;
        }
        if (null === $value || \is_bool($value) || \is_int($value) || \is_string($value)) {
            return $value;
        }
        throw new \InvalidArgumentException('Artifact values must be exact JSON scalars.');
    }
}
