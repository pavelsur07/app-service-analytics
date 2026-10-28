<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Messenger;

use App\Ingestion\Application\FactCaptureContext;
use App\Ingestion\Application\Message\FetchOzonPostingsMessage;
use App\Ingestion\Application\Message\FetchOzonReturnsMessage;
use App\Ingestion\Domain\FactCaptureOrigin;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\HandlerArgumentsStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\SerializerStamp;

final class FactCaptureOriginMiddleware implements MiddlewareInterface
{
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();
        if (($message instanceof FetchOzonPostingsMessage || $message instanceof FetchOzonReturnsMessage)
            && null !== $envelope->last(ReceivedStamp::class)) {
            $value = $envelope->last(SerializerStamp::class)?->getContext()[FactCaptureContext::KEY] ?? null;
            $origin = \is_string($value) ? FactCaptureOrigin::tryFrom($value) : null;
            $envelope = $envelope->withoutAll(HandlerArgumentsStamp::class)->with(new HandlerArgumentsStamp([($origin ?? FactCaptureOrigin::Unknown)->value]));
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
