<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Application\Message\FetchOzonExpensesMessage;
use Psr\Container\ContainerInterface as PsrContainerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;

/**
 * Выдержка между повторными попытками — не украшение конфигурации,
 * а единственное, что отделяет секундную сетевую заминку от потерянных
 * данных.
 *
 * Проверено дорого. 15–16 августа 2026 загрузка падала по три-пять раз
 * в час на отказе резолва имени; все попытки укладывались в семь секунд
 * и сгорали внутри самой заминки, после чего сообщение уходило
 * в очередь отказов и ждало человека. При настоящей выдержке вторая
 * попытка пришлась бы на момент, когда всё уже работает.
 *
 * Тест держит именно это свойство: первая повторная попытка ждёт
 * около тридцати секунд, а пятая — минуты, а не миллисекунды. Числа
 * сверяются с допуском на дрожание (jitter), которое Symfony добавляет
 * намеренно, чтобы попытки не били в одну секунду.
 */
final class MessengerRetryDelayTest extends KernelTestCase
{
    /**
     * Столько Symfony ждёт по умолчанию: 1, 2, 4 секунды. Для площадки,
     * отвечающей ошибкой минуту, это одна попытка, повторённая трижды.
     */
    private const int DEFAULT_FIRST_DELAY_MS = 1000;

    public function testFirstRetryWaitsAboutHalfAMinute(): void
    {
        $delay = $this->waitingTime(retriesSoFar: 0);

        // 30 секунд минус десять процентов дрожания.
        self::assertGreaterThanOrEqual(27_000, $delay);
        self::assertLessThanOrEqual(33_000, $delay);

        // Явно и грубо: если однажды конфигурация потеряется и включится
        // умолчание Symfony, тест обязан упасть здесь, а не оставить
        // «почти похожее» число.
        self::assertGreaterThan(self::DEFAULT_FIRST_DELAY_MS * 10, $delay);
    }

    public function testDelayGrowsAndIsCappedByTenMinutes(): void
    {
        // 30 с → 90 с → 270 с → 600 с (упёрлось в потолок) → 600 с.
        self::assertGreaterThanOrEqual(81_000, $this->waitingTime(1));
        self::assertGreaterThanOrEqual(243_000, $this->waitingTime(2));

        $capped = $this->waitingTime(4);
        self::assertGreaterThanOrEqual(540_000, $capped);
        self::assertLessThanOrEqual(660_000, $capped);
    }

    public function testWholeRetryWindowOutlastsAMinuteLongOutage(): void
    {
        $total = 0;
        for ($retries = 0; $retries < 5; ++$retries) {
            $total += $this->waitingTime($retries);
        }

        // Суммарно около получаса. Именно это число отвечает на вопрос
        // «переживёт ли загрузка сбой площадки», и оно должно быть
        // заметно больше самой длинной заминки, которую мы видели.
        self::assertGreaterThan(20 * 60 * 1000, $total);
    }

    private function waitingTime(int $retriesSoFar): int
    {
        self::bootKernel();

        /** @var PsrContainerInterface $locator */
        $locator = self::getContainer()->get('messenger.retry_strategy_locator');

        /** @var RetryStrategyInterface $strategy */
        $strategy = $locator->get('async_ingestion');

        $envelope = new Envelope(new FetchOzonExpensesMessage(
            companyId: '019ffe00-0000-7000-8000-000000000001',
            marketplaceAccountId: '019ffe00-0000-7000-8000-000000000002',
            accrualDate: '2026-08-16',
        ));

        for ($i = 0; $i < $retriesSoFar; ++$i) {
            $envelope = $envelope->with(new \Symfony\Component\Messenger\Stamp\RedeliveryStamp($i + 1));
        }

        return $strategy->getWaitingTime($envelope, new \RuntimeException('сетевая заминка'));
    }
}
