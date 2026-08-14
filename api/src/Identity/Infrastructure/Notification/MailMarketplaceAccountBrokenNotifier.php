<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Notification;

use App\Identity\Domain\MarketplaceAccount;
use App\Identity\Domain\MarketplaceAccountBrokenNotifier;
use App\Identity\Infrastructure\Query\CompanyMemberEmailsQuery;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Письмо участникам компании о сломанном подключении (ADR-007).
 *
 * Symfony Mailer (CLAUDE.md §8), отправитель — из учётных данных SMTP
 * (config/packages/mailer.yaml), здесь не задаётся.
 *
 * Текст говорит, что именно переподключить, и честно говорит как: своего
 * экрана для замены ключей пока нет, поэтому просим написать нам. Обещать
 * кнопку, которой не существует, хуже, чем признать ручной шаг.
 */
final readonly class MailMarketplaceAccountBrokenNotifier implements MarketplaceAccountBrokenNotifier
{
    public function __construct(
        private CompanyMemberEmailsQuery $memberEmails,
        private MailerInterface $mailer,
    ) {
    }

    public function accountBroken(string $companyId, MarketplaceAccount $account): void
    {
        $recipients = $this->recipients($companyId);
        if ([] === $recipients) {
            // Компания без участников — состояние, которого в продукте нет
            // (владелец заводится вместе с компанией). Молчать нельзя:
            // иначе сломанное подключение осталось бы незамеченным ровно
            // так, как ADR-007 запрещает.
            throw new \RuntimeException("У компании {$companyId} нет ни одного участника — письмо о сломанном подключении отправить некому.");
        }

        $shop = $account->externalShopId();
        // Название площадки — из самого подключения, не константой в тексте:
        // интерфейс общий, и со вторым коннектором письмо про Ozon ушло бы
        // клиенту Wildberries.
        $marketplace = ucfirst($account->marketplace()->value);

        $this->mailer->send(
            (new Email())
                ->to(...$recipients)
                ->subject("Conwix: подключение {$marketplace} перестало работать")
                ->text(
                    "Площадка отклонила ключи подключения (магазин {$shop}).\n"
                    ."Синхронизация остановлена, данные не удалены — история\n"
                    ."остаётся на месте и продолжит обновляться после починки.\n\n"
                    ."Что произошло: {$marketplace} ответил отказом в авторизации.\n"
                    ."Обычно это значит, что ключ доступа отозван или перевыпущен\n"
                    ."в кабинете продавца.\n\n"
                    ."Что сделать: выпустите новый ключ в кабинете {$marketplace}\n"
                    ."(Настройки → API-ключи) и напишите нам — заменим.\n"
                    ."Своего экрана для замены ключей пока нет.\n\n"
                    ."Пока подключение не восстановлено, цифры в приложении\n"
                    ."остаются на дате последней успешной синхронизации.\n"
                ),
        );
    }

    /**
     * @return list<string>
     */
    private function recipients(string $companyId): array
    {
        $rows = $this->memberEmails->build($companyId)->executeQuery()->fetchAllAssociative();

        return array_map(
            static fn (array $row): string => CompanyMemberEmailsQuery::mapRow($row)->email,
            $rows,
        );
    }
}
