<?php

declare(strict_types=1);

// Old message definitions and vendor classes only; new origin enum autoload is forbidden.

namespace App\Ingestion\Application\Message {
    final readonly class FetchOzonPostingsMessage
    {
        public function __construct(public string $companyId, public string $marketplaceAccountId, public string $businessDate)
        {
        }
    }

    final readonly class FetchOzonReturnsMessage
    {
        public function __construct(public string $companyId, public string $marketplaceAccountId, public string $from, public string $to)
        {
        }
    }
}

namespace {
    require dirname(__DIR__, 3).'/vendor/autoload.php';
    spl_autoload_register(static function (string $class): void {
        if ('App\\Ingestion\\Domain\\FactCaptureOrigin' === $class) {
            throw new RuntimeException('Old release does not contain FactCaptureOrigin.');
        }
    }, prepend: true);
    set_error_handler(static function (int $severity, string $message): never {
        throw new ErrorException($message, 0, $severity);
    });
    try {
        $serializer = new Symfony\Component\Messenger\Transport\Serialization\PhpSerializer();
        if ('encode' === ($argv[1] ?? null)) {
            $old = new App\Ingestion\Application\Message\FetchOzonPostingsMessage('company', 'account', '2026-10-28');
            fwrite(\STDOUT, json_encode($serializer->encode(new Symfony\Component\Messenger\Envelope($old)), \JSON_THROW_ON_ERROR));
            exit(0);
        }
        $encoded = json_decode(stream_get_contents(\STDIN), true, flags: \JSON_THROW_ON_ERROR);
        if (!is_array($encoded) || !is_string($encoded['body'] ?? null)) {
            throw new RuntimeException('Invalid envelope.');
        }
        $envelope = $serializer->decode(['body' => $encoded['body']]);
        $message = $envelope->getMessage();
        if (!$message instanceof App\Ingestion\Application\Message\FetchOzonPostingsMessage
            && !$message instanceof App\Ingestion\Application\Message\FetchOzonReturnsMessage) {
            throw new RuntimeException('Unexpected legacy message.');
        }
        $stamp = $envelope->last(Symfony\Component\Messenger\Stamp\SerializerStamp::class);
        fwrite(\STDOUT, json_encode(['message' => get_object_vars($message), 'context' => $stamp?->getContext()], \JSON_THROW_ON_ERROR));
    } catch (Throwable $failure) {
        fwrite(\STDERR, $failure::class.': '.$failure->getMessage());
        exit(1);
    }
}
