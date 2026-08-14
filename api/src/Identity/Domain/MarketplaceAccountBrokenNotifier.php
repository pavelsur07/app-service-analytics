<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * Уведомление клиента о сломанном подключении (ADR-007: ответ площадки
 * об отсутствии авторизации переводит подключение в broken, синхронизация
 * останавливается, клиенту отправляется письмо).
 *
 * Интерфейс в Domain, реализация в Infrastructure — тот же приём, что
 * у MarketplaceCredentialsEncryptor. Так Application-сценарий не знает
 * ни про Symfony Mailer, ни про то, как ищутся адреса участников
 * компании, и граница модуля не расширяется ради одного письма.
 */
interface MarketplaceAccountBrokenNotifier
{
    /**
     * $companyId первым параметром (CLAUDE.md §1): адреса получателей
     * ищутся в границах компании, не по всей базе.
     */
    public function accountBroken(string $companyId, MarketplaceAccount $account): void;
}
