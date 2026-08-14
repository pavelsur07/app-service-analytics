<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * Что именно записано в аудит-журнал. Строкой, а не enum-колонкой:
 * список событий растёт вместе с продуктом (себестоимость, планы, вход
 * администратора), и миграция на каждое новое действие — цена без выгоды.
 */
final class AuditAction
{
    public const string MarketplaceCredentialsReplaced = 'marketplace_account.credentials_replaced';

    private function __construct()
    {
    }
}
