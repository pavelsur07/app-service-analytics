<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * Исход попытки удалить подключение, которое, по утверждению вызывающего,
 * ничего не загрузило.
 *
 * `InUse` намеренно не называет причину «есть raw-документы» — Identity
 * не знает, что это такое (зависимости строго вниз, Ingestion ему
 * не видим); имя нейтральное, потому что решение принесено снаружи
 * колбэком (MarketplaceAccountRepository::deleteIfNoHistory), а не найдено
 * здесь.
 */
enum DiscardAccountOutcome
{
    case Discarded;
    case NotFound;
    /** Колбэк вызывающего отказал: у подключения есть то, что закрывать. */
    case InUse;
}
