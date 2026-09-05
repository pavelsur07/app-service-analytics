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
use Symfony\Component\Messenger\MessageBusInterface;

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
 */
final readonly class ConnectOzonAccountAction
{
    private const int PROBE_LIMIT = 1;

    public function __construct(
        private OzonCatalogFetcher $client,
        private IdentityFacade $identityFacade,
        private MessageBusInterface $bus,
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

            // Лимит запросов, сбой площадки, обрыв сети. В отличие
            // от замены ключей, недоступность здесь не пробрасывается
            // исключением: ADR-021 требует именно трёх различимых
            // исходов, а 500 не может честно сказать «попробуйте позже».
            return ConnectOzonAccountOutcome::failed(ConnectOzonAccountResult::Unavailable);
        }

        $connection = $this->identityFacade->connectOzonAccount($companyId, $name, $clientId, $apiKey, $actorUserId);
        if (MarketplaceAccountConnectionOutcome::AlreadyConnected === $connection->outcome) {
            return ConnectOzonAccountOutcome::failed(ConnectOzonAccountResult::AlreadyConnected);
        }

        \assert(null !== $connection->accountId);
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
