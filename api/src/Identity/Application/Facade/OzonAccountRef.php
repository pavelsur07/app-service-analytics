<?php

declare(strict_types=1);

namespace App\Identity\Application\Facade;

/**
 * Только идентификаторы — в отличие от OzonSyncTarget, не несёт
 * расшифрованные credentials: планировщику они не нужны, расшифровка
 * происходит позже, внутри FetchOzonPostingsHandler, только для
 * аккаунта, который реально обрабатывается.
 */
final readonly class OzonAccountRef
{
    public function __construct(
        public string $companyId,
        public string $marketplaceAccountId,
        /**
         * Рекламный ключ подключён и исправен (ADR-026 п. 1). Планировщик
         * ставит загрузку рекламы и контроль её свежести только таким
         * подключениям: кабинет без рекламы не должен выглядеть сломанным.
         */
        public bool $advertisingActive,
        /**
         * Момент подключения кабинета. Сторож свежести не ждёт суточную
         * выгрузку (остатки, ADR-034) от кабинета, подключённого меньше
         * порога назад: тревога, которая врёт, перестаёт читаться.
         */
        public \DateTimeImmutable $connectedAt,
    ) {
    }
}
