<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Storage;

use App\Ingestion\Domain\NetForwardArtifactKey;
use App\Ingestion\Domain\NetForwardArtifactMissing;
use App\Ingestion\Domain\NetForwardArtifactStorage;
use AsyncAws\Core\Exception\Http\ClientException;
use AsyncAws\S3\Exception\NoSuchKeyException;
use AsyncAws\S3\S3Client;

/** Plain bounded objects, independent of provider conditional-write support. */
final readonly class S3NetForwardArtifactStorage implements NetForwardArtifactStorage
{
    public function __construct(private S3Client $s3, private string $rawStorageBucket)
    {
    }

    /** @param (\Closure(int): void)|null $beforeRead */
    public function putContent(string $companyId, string $accountId, NetForwardArtifactKey $key, string $body, ?\Closure $beforeRead = null): void
    {
        $key->assertScope($companyId, $accountId);
        $size = \strlen($body);
        if ($size < 1 || $size > 1048576 || hash('sha256', $body) !== $key->sha256) {
            throw new \InvalidArgumentException('Artifact body does not match bounded content key.');
        }
        // The artifact-only injected client has no SDK/HTTP retries. At most two PUTs.
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $this->s3->putObject(['Bucket' => $this->rawStorageBucket, 'Key' => $key->toString(), 'Body' => $body, 'ContentType' => 'application/json', 'IfNoneMatch' => '*'])->resolve();
            } catch (\Throwable $failure) {
                try {
                    if ($body === $this->readBack($companyId, $accountId, $key, $size, $beforeRead)) {
                        return;
                    }
                    throw new \UnexpectedValueException('Artifact read-back mismatch.');
                } catch (NetForwardArtifactMissing) {
                    if (0 === $attempt) {
                        continue;
                    }
                    throw new \RuntimeException('Artifact PUT failed.', 0, $failure);
                }
            }
            if ($body !== $this->readBack($companyId, $accountId, $key, $size, $beforeRead)) {
                throw new \UnexpectedValueException('Artifact read-back mismatch.');
            }

            return;
        }
    }

    /** @param (\Closure(int): void)|null $beforeRead */
    private function readBack(string $companyId, string $accountId, NetForwardArtifactKey $key, int $size, ?\Closure $beforeRead): string
    {
        $beforeRead?->__invoke($size);

        return $this->get($companyId, $accountId, $key, $size);
    }

    public function get(string $companyId, string $accountId, NetForwardArtifactKey $key, int $maxBytes): string
    {
        $key->assertScope($companyId, $accountId);
        if ($maxBytes < 1 || $maxBytes > 1048576) {
            throw new \InvalidArgumentException('Invalid artifact read byte limit.');
        }
        try {
            $object = $this->s3->getObject(['Bucket' => $this->rawStorageBucket, 'Key' => $key->toString()]);
            $length = $object->getContentLength();
            if ((null !== $length && ($length < 1 || $length > $maxBytes)) || null !== $object->getContentEncoding()) {
                throw new \UnexpectedValueException('Invalid artifact length or content encoding.');
            }
            $body = '';
            foreach ($object->getBody()->getChunks() as $chunk) {
                if (\strlen($chunk) > $maxBytes - \strlen($body)) {
                    throw new \OverflowException('Artifact stream byte limit exceeded.');
                }
                $body .= $chunk;
            }
            if ((null !== $length && $length !== \strlen($body)) || hash('sha256', $body) !== $key->sha256) {
                throw new \UnexpectedValueException('Artifact integrity mismatch.');
            }

            return $body;
        } catch (NoSuchKeyException $error) {
            throw new NetForwardArtifactMissing('Artifact object missing.', 0, $error);
        } catch (ClientException $error) {
            if (404 === $error->getCode()) {
                throw new NetForwardArtifactMissing('Artifact object missing.', 0, $error);
            }
            throw $error;
        }
    }
}
