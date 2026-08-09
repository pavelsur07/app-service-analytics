<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Shared\Infrastructure\Observability\SentryEventScrubber;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

/**
 * config/packages/sentry.yaml: messenger.capture_soft_fails: false —
 * событие должно уходить в GlitchTip на финальный отказ обработчика
 * (WorkerMessageFailedEvent::willRetry() === false), но не на каждую
 * попытку ретрая. До этого теста оба реальных отказа (сетевой на проде
 * и лимит запросов Ozon) проходили полностью незамеченными.
 *
 * Собирает настоящий Sentry\State\Hub с настоящим Client (тот же
 * SentryEventScrubber, что в проде через before_send), но с транспортом-
 * шпионом вместо реальной сети — так же, как FetchOzonPostingsHandlerTest
 * подменяет HTTP-клиент, а не пишет свой парсер ответа Ozon.
 */
final class SentryMessengerFailureTest extends KernelTestCase
{
    public function testFinalFailureIsCapturedExactlyOnce(): void
    {
        $transport = $this->replaceHubWithSpyTransport();
        $dispatcher = $this->dispatcher();
        $exception = new \RuntimeException('final failure');

        // WorkerMessageFailedEvent сам по себе не решает willRetry() —
        // это делает штатный Symfony\...\SendFailedMessageForRetryListener,
        // который срабатывает раньше Sentry (реально проверено при
        // отладке этого теста) и сам решает retry по стратегии
        // async_ingestion (по умолчанию max_retries=3), глядя на
        // RedeliveryStamp конверта. Без явного RedeliveryStamp(3)
        // (ретраи уже исчерпаны) он считает это первой попыткой и всегда
        // помечает событие на повтор — тогда Sentry увидит willRetry()
        // === true и не отправит событие вовсе (capture_soft_fails: false).
        $dispatcher->dispatch(new WorkerMessageFailedEvent(
            new Envelope(new \stdClass(), [new RedeliveryStamp(3)]),
            'async_ingestion',
            $exception,
        ));

        self::assertCount(1, $transport->sent);
        $capturedException = $transport->sent[0]->getExceptions()[0];
        self::assertSame($exception::class, $capturedException->getType());
        self::assertSame($exception->getMessage(), $capturedException->getValue());
    }

    public function testRetryableFailureIsNotCaptured(): void
    {
        $transport = $this->replaceHubWithSpyTransport();
        $dispatcher = $this->dispatcher();

        // Без RedeliveryStamp — SendFailedMessageForRetryListener видит
        // первую попытку из max_retries=3 и помечает событие на повтор.
        $dispatcher->dispatch(new WorkerMessageFailedEvent(
            new Envelope(new \stdClass()),
            'async_ingestion',
            new \RuntimeException('will retry'),
        ));

        self::assertCount(0, $transport->sent);
    }

    private function replaceHubWithSpyTransport(): SpySentryTransport
    {
        self::bootKernel();

        $transport = new SpySentryTransport();
        $client = ClientBuilder::create(['before_send' => new SentryEventScrubber()])
            ->setTransport($transport)
            ->getClient();

        self::getContainer()->set(HubInterface::class, new Hub($client));

        return $transport;
    }

    private function dispatcher(): EventDispatcherInterface
    {
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get('event_dispatcher');

        return $dispatcher;
    }
}

final class SpySentryTransport implements TransportInterface
{
    /** @var list<Event> */
    public array $sent = [];

    public function send(Event $event): Result
    {
        $this->sent[] = $event;

        return new Result(ResultStatus::success(), $event);
    }

    public function close(?int $timeout = null): Result
    {
        return new Result(ResultStatus::success());
    }
}
