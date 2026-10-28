<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Application\FactCaptureContext;
use App\Ingestion\Application\Message\FetchOzonPostingsMessage;
use App\Ingestion\Application\Message\FetchOzonReturnsMessage;
use App\Ingestion\Application\Messenger\FactCaptureOriginMiddleware;
use App\Ingestion\Domain\FactCaptureOrigin;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\HandlerArgumentsStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\SerializerStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Process\Process;

final class FactCaptureWireCompatibilityTest extends TestCase
{
    public function testNewProducerIsReadableByTheOldReadonlyMessagesWithoutNewEnum(): void
    {
        foreach ([
            new FetchOzonPostingsMessage('company', 'account', '2026-10-28', FactCaptureOrigin::LivePolling),
            new FetchOzonReturnsMessage('company', 'account', '2026-10-01', '2026-10-28', FactCaptureOrigin::HistoricalBackfill),
        ] as $message) {
            $legacy = new Process([\PHP_BINARY, __DIR__.'/../../Support/Ingestion/legacy-fact-message.php']);
            $envelope = new Envelope($message, FactCaptureContext::stamps($message->captureOrigin()));
            $legacy->setInput(json_encode((new PhpSerializer())->encode($envelope), \JSON_THROW_ON_ERROR));
            $legacy->run();
            self::assertSame(0, $legacy->getExitCode(), $legacy->getErrorOutput());
            $decoded = json_decode($legacy->getOutput(), true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            self::assertIsArray($decoded['message']);
            self::assertIsArray($decoded['context']);
            self::assertSame('company', $decoded['message']['companyId']);
            self::assertSame('account', $decoded['message']['marketplaceAccountId']);
            self::assertSame($message->captureOrigin()->value, $decoded['context']['conwix_fact_capture_origin_v1']);
        }
    }

    public function testActualLegacyEnvelopeDecodesAsUnknownOnTheNewConsumer(): void
    {
        $legacy = new Process([\PHP_BINARY, __DIR__.'/../../Support/Ingestion/legacy-fact-message.php', 'encode']);
        $legacy->mustRun();
        $encoded = json_decode($legacy->getOutput(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($encoded);
        self::assertIsString($encoded['body']);
        $message = (new PhpSerializer())->decode(['body' => $encoded['body']])->getMessage();
        self::assertInstanceOf(FetchOzonPostingsMessage::class, $message);
        self::assertSame(FactCaptureOrigin::Unknown, $message->captureOrigin());
    }

    public function testMiddlewarePreservesScalarOriginAcrossRetryWithoutSendingHandlerArguments(): void
    {
        $message = new FetchOzonPostingsMessage('company', 'account', '2026-10-28', FactCaptureOrigin::LivePolling);
        $serializer = new PhpSerializer();
        $sent = new Envelope($message, FactCaptureContext::stamps(FactCaptureOrigin::LivePolling));
        $received = $serializer->decode($serializer->encode($sent))->with(new ReceivedStamp('async_ingestion'));
        $handled = (new FactCaptureOriginMiddleware())->handle($received, new StackMiddleware());
        self::assertSame(['live_polling'], $handled->last(HandlerArgumentsStamp::class)?->getAdditionalArguments());
        $retry = $serializer->decode($serializer->encode($handled));
        self::assertNull($retry->last(HandlerArgumentsStamp::class));
        self::assertSame('live_polling', $retry->last(SerializerStamp::class)?->getContext()['conwix_fact_capture_origin_v1']);
        $handledAgain = (new FactCaptureOriginMiddleware())->handle($retry->with(new ReceivedStamp('async_ingestion')), new StackMiddleware());
        self::assertSame(['live_polling'], $handledAgain->last(HandlerArgumentsStamp::class)?->getAdditionalArguments());
    }

    public function testMissingMalformedTransportContextAndUnrelatedMessages(): void
    {
        $middleware = new FactCaptureOriginMiddleware();
        $message = new FetchOzonReturnsMessage('c', 'a', '2026-10-01', '2026-10-28', FactCaptureOrigin::LivePolling);
        foreach ([[], ['conwix_fact_capture_origin_v1' => 17], ['conwix_fact_capture_origin_v1' => ['live_polling']], ['conwix_fact_capture_origin_v1' => 'invented']] as $context) {
            $envelope = new Envelope($message, [new SerializerStamp($context), new ReceivedStamp('async_ingestion')]);
            self::assertSame(['unknown'], $middleware->handle($envelope, new StackMiddleware())->last(HandlerArgumentsStamp::class)?->getAdditionalArguments());
        }
        $direct = new Envelope($message);
        self::assertSame($direct, $middleware->handle($direct, new StackMiddleware()));
        $other = new Envelope(new \stdClass(), [new SerializerStamp(['conwix_fact_capture_origin_v1' => 'live_polling']), new ReceivedStamp('async_ingestion')]);
        self::assertSame($other, $middleware->handle($other, new StackMiddleware()));
    }
}
