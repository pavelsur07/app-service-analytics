<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\MarketplaceAccountBrokenNotifier;
use App\Identity\Domain\MarketplaceAccountRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Площадка отказала рекламному ключу (ADR-026 п. 1): в `broken` переходит
 * только реклама, клиент получает письмо про рекламный ключ. Подключение
 * и загрузка продаж и расходов не затрагиваются — это отступление
 * от ADR-007, названное в ADR-026.
 *
 * Устройство то же, что у `MarkMarketplaceAccountBrokenAction`, и по тем же
 * причинам: условный UPDATE решает, кому отправлять письмо (несколько
 * обработчиков рекламы получат отказ одновременно), а исчезновение строки
 * между переводом и чтением — запись в журнал, не исключение: переход уже
 * закоммичен, и ретрай упал бы на уже сломанной рекламе.
 *
 * Единственный вызывающий — `IdentityFacade::markOzonAdvertisingBroken()`.
 */
final readonly class MarkAdvertisingBrokenAction
{
    public function __construct(
        private MarketplaceAccountRepository $accounts,
        private MarketplaceAccountBrokenNotifier $notifier,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(string $companyId, string $marketplaceAccountId): bool
    {
        $id = Uuid::fromString($marketplaceAccountId);

        if (!$this->accounts->markAdvertisingBrokenIfActive($companyId, $id)) {
            return false;
        }

        $account = $this->accounts->get($companyId, $id);
        if (null === $account) {
            try {
                $this->logger->warning('Реклама переведена в broken, но подключение исчезло до отправки уведомления', [
                    'company_id' => $companyId,
                    'marketplace_account_id' => $marketplaceAccountId,
                ]);
            } catch (\Throwable) {
                // Запись — диагностика; переход уже закоммичен, отказ
                // журнала не должен сорвать обработчик очереди.
            }

            return true;
        }

        $this->notifier->advertisingBroken($companyId, $account);

        return true;
    }
}
