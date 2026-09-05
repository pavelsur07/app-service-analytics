<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

use App\Identity\Application\Facade\IdentityFacade;
use App\Identity\Application\Facade\MarketplaceAccountConnectionOutcome;
use App\Ingestion\Application\Message\FetchOzonCatalogMessage;
use App\Ingestion\Application\Message\FetchOzonExpensesMessage;
use App\Ingestion\Application\Message\FetchOzonPostingsMessage;
use App\Ingestion\Application\Message\FetchOzonReturnsMessage;
use App\Ingestion\Domain\OzonAuthorizationFailure;
use App\Ingestion\Domain\OzonCatalogFetcher;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;

/**
 * Подключение кабинета Ozon при онбординге (ADR-021).
 *
 * Порядок важен и повторяет ReplaceOzonCredentialsAction: сохранить
 * непроверенный ключ значит завести подключение, которое станет broken
 * через несколько часов, — а клиент будет считать, что всё настроил.
 *
 * Живёт в Ingestion, хотя пишет данные Identity: проверка требует похода
 * в площадку, клиент площадки принадлежит Ingestion, а зависимости
 * строго вниз.
 *
 * Проба различает три ветви отказа, а не две, — по тому, кто должен
 * узнать об отказе и как:
 *
 * 1. `OzonAuthorizationFailure` (401/403) — ключ отклонён самой площадкой.
 *    Клиенту нужно другое действие (перевыпустить ключ), поэтому это
 *    отдельный исход `Rejected`.
 * 2. Любой другой отказ HTTP-клиента (сеть, таймаут, лимит запросов,
 *    прочие 4xx и 5xx) — площадка не ответила по причине, которая лечится
 *    повтором, а не новым ключом. `Unavailable` — ожидаемое доменное
 *    условие уровня warning, не наша ошибка, и в трекер намеренно
 *    не идёт: обработчика Sentry в конфиге журнала нет (CLAUDE.md,
 *    «Наблюдаемость»), и топить в нём такие случаи означало бы прятать
 *    в шуме настоящие ошибки.
 * 3. Всё остальное (`TypeError`, `Error`, `LogicException` нашего кода) —
 *    не отказ площадки, а наш дефект. Спрятать его под «Ozon недоступен»
 *    значило бы навсегда лишить себя шанса его увидеть: клиент решит,
 *    что дело в площадке, а трекер об этом не узнает никогда. Поэтому
 *    эта ветвь пробрасывается, а не превращается в исход.
 */
final readonly class ConnectOzonAccountAction
{
    private const int PROBE_LIMIT = 1;

    public function __construct(
        private OzonCatalogFetcher $client,
        private IdentityFacade $identityFacade,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(
        string $companyId,
        string $name,
        string $clientId,
        string $apiKey,
        string $actorUserId,
    ): ConnectOzonAccountOutcome {
        try {
            $this->client->fetchPage($clientId, $apiKey, '', self::PROBE_LIMIT);
        } catch (\Throwable $failure) {
            if (OzonAuthorizationFailure::isAuthorizationFailure($failure)) {
                return ConnectOzonAccountOutcome::failed(ConnectOzonAccountResult::Rejected);
            }

            if (!$failure instanceof HttpClientExceptionInterface) {
                // Не «площадка недоступна» — наш дефект (опечатка в коде,
                // отсутствующий метод, нарушенный инвариант). Он обязан
                // выглядеть как наш: дойти до трекера и стать 500,
                // а не спрятаться под благополучным на вид исходом.
                throw $failure;
            }

            // Лимит запросов, сбой площадки, обрыв сети, прочие отказы
            // HTTP-клиента. В отличие от замены ключей, недоступность
            // здесь не пробрасывается исключением: ADR-021 требует именно
            // трёх различимых исходов, а 500 не может честно сказать
            // «попробуйте позже». api_key в журнал не попадает ни в каком
            // виде; request_id и company_id добавляет RequestContextProcessor.
            $this->logger->warning('Ozon не ответил при проверке ключей подключения', [
                'client_id' => $clientId,
            ]);

            return ConnectOzonAccountOutcome::failed(ConnectOzonAccountResult::Unavailable);
        }

        $connection = $this->identityFacade->connectOzonAccount($companyId, $name, $clientId, $apiKey, $actorUserId);
        if (MarketplaceAccountConnectionOutcome::AlreadyConnected === $connection->outcome) {
            return ConnectOzonAccountOutcome::failed(ConnectOzonAccountResult::AlreadyConnected);
        }

        if (null === $connection->accountId) {
            // `assert()` не годится здесь: в боевой конфигурации
            // `zend.assertions=-1` компилирует его прочь, и проверка
            // не выполняется вовсе. Настоящая проверка нужна, чтобы
            // нарушенный инвариант дошёл до трекера как наш дефект,
            // а не привёл к TypeError чуть ниже на передаче null
            // в scheduleInitialBackfill(string $accountId).
            throw new \LogicException('Нарушен инвариант: исход подключения Connected обязан нести accountId (MarketplaceAccountConnection::connected()), а пришёл пустым.');
        }
        $this->scheduleInitialBackfill($companyId, $connection->accountId);

        return ConnectOzonAccountOutcome::connected($connection->accountId);
    }

    /**
     * Ступень 1 (ADR-021): текущий месяц, сразу, вперёд остальных.
     * Каталог — снимок текущего состояния, глубины у него нет, поэтому
     * одним сообщением.
     */
    private function scheduleInitialBackfill(string $companyId, string $accountId): void
    {
        $this->bus->dispatch(new FetchOzonCatalogMessage($companyId, $accountId));

        $businessDates = InitialBackfillWindow::businessDates(new \DateTimeImmutable());
        foreach ($businessDates as $businessDate) {
            $this->bus->dispatch(new FetchOzonPostingsMessage($companyId, $accountId, $businessDate));
            $this->bus->dispatch(new FetchOzonExpensesMessage($companyId, $accountId, $businessDate));
        }

        // Возвраты принимают диапазон, а не один день (FetchOzonReturnsMessage:
        // from/to), поэтому уходят одним сообщением на весь месяц. Тридцать
        // сообщений с диапазоном в один день отработали бы, но потратили бы
        // квоту площадки тридцатикратно.
        $first = $businessDates[0] ?? null;
        $last = $businessDates[\count($businessDates) - 1] ?? null;
        if (null !== $first && null !== $last) {
            $this->bus->dispatch(new FetchOzonReturnsMessage($companyId, $accountId, $first, $last));
        }
    }
}
