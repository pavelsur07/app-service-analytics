<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * Что именно записано в аудит-журнал. Строкой, а не enum-колонкой:
 * список событий растёт вместе с продуктом (себестоимость, планы, вход
 * администратора), и миграция на каждое новое действие — цена без выгоды.
 *
 * Действия системного контура (ADR-017) названы по предмету, а не по
 * экрану: за них отвечает администратор, но записаны они о компании
 * и об администраторе, а не о кнопке, которой нажали.
 */
final class AuditAction
{
    public const string MarketplaceCredentialsReplaced = 'marketplace_account.credentials_replaced';

    /** Подключение кабинета при онбординге (ADR-021). */
    public const string MarketplaceAccountConnected = 'marketplace_account.connected';

    /**
     * Удаление подключения, которое ничего не загрузило (решение
     * владельца: опечатка в кабинете — не актив, закрывать нечего).
     * Строка исчезает целиком, и без этой записи не у кого было бы
     * спросить, что подключение вообще существовало.
     */
    public const string MarketplaceAccountDiscarded = 'marketplace_account.discarded';

    /** Заведён Admin. Компании у события нет (ADR-017). */
    public const string AdministratorCreated = 'administrator.created';

    /** Зарегистрирован клиентский аккаунт: компания и её владелец. */
    public const string CompanyRegistered = 'company.registered';

    /** Пользователь подтвердил владение адресом электронной почты. */
    public const string UserEmailConfirmed = 'user.email_confirmed';

    public const string CompanyBlocked = 'company.blocked';

    public const string CompanyActivated = 'company.activated';

    private function __construct()
    {
    }
}
