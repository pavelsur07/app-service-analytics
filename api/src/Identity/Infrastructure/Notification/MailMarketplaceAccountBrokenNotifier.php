<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Notification;

use App\Identity\Domain\MarketplaceAccount;
use App\Identity\Domain\MarketplaceAccountBrokenNotifier;
use App\Identity\Infrastructure\Query\CompanyMemberEmailsQuery;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Письмо участникам компании о сломанном подключении (ADR-007).
 *
 * Symfony Mailer (CLAUDE.md §8), отправитель — из учётных данных SMTP
 * (config/packages/mailer.yaml), здесь не задаётся.
 *
 * Текст говорит, что именно переподключить и как: клиент выпускает новый
 * ключ в кабинете площадки и сам заменяет его на экране «Подключения» —
 * тот же гейт уже приводит туда сломанное подключение. «Напишите нам»
 * остаётся запасным путём, не основным: экран для замены ключей
 * существует и работает, отправлять клиента в переписку за тем, что он
 * делает в два клика, было бы неправдой.
 *
 * **Отправка оставляет след в журнале уровнем `warning`, не `info`.**
 * Порог журнала в prod — `warning` (`monolog.yaml`, `when@prod`): запись
 * уровнем `info` туда попросту не попала бы. `warning` здесь честен
 * по смыслу — событие происходит только при поломке подключения, то есть
 * само по себе нештатно, а не рутинный трафик. В контекст идут
 * `company_id`, `marketplace_account_id`, `external_shop_id`
 * и `recipients_count` — число получателей, не сами адреса: адрес
 * участника — персональные данные, а по `company_id` они и так
 * восстанавливаются отдельным запросом (`CompanyMemberEmailsQuery`),
 * когда это действительно понадобится.
 *
 * **Отказ отправки — тот же класс хрупкости, что был вчера закрыт
 * в `OzonAccountBrokenLogger`.** `MarkMarketplaceAccountBrokenAction`
 * меняет состояние подключения на `broken`, затем вызывает этот
 * уведомитель; переход уже совершён и откатывать его не для чего.
 * Если бы `$this->mailer->send()` бросил и это исключение ушло наружу,
 * сообщение очереди уехало бы в ретраи, а на повторе подключение уже
 * не `active` — вызывающий обработчик (например, `FetchOzonPostingsHandler`,
 * который ищет только активные подключения через `findOzonSyncTarget`)
 * не найдёт его и упадёт с «not found», хотя переход и письмо (или его
 * честная неудача) уже случились. Диагностика не имеет права ломать
 * наблюдаемое поведение — то же основание, что у `OzonAccountBrokenLogger`.
 * Поэтому отказ отправки перехватывается здесь и **не глотается молча**:
 * ADR-007 прямо запрещает «молчаливую остановку синхронизации», и письмо,
 * которое тихо не дошло, — ровно такой случай. Запись о неудаче — тем же
 * уровнем `warning`, с тем же набором полей плюс класс и **вычищенное**
 * сообщение исключения.
 *
 * **Сообщение исключения эхом может содержать адрес получателя, и это
 * не гипотетический риск.** `Symfony\Component\Mailer\Transport\Smtp\SmtpTransport`
 * собирает `UnexpectedResponseException` из сырого ответа SMTP-сервера
 * (`sprintf(', with message "%s"', trim($response))`), а типовой отказ
 * SMTP по стандарту возвращает отклонённый адрес прямо в тексте:
 * `550 5.1.1 <user@example.com>: Recipient address rejected: User unknown`.
 * Положить такое сообщение в контекст сырым значило бы нарушить
 * собственное правило этого класса — не класть адреса в журнал —
 * тем же путём, каким его вчера обошёл бы неусечённый `response_body`
 * в `OzonAccountBrokenLogger`. Поэтому `redactedExceptionMessage()`
 * маскирует любую email-подобную подстроку регулярным выражением
 * (не точным совпадением с известными адресами получателей: транспорт
 * формирует свой текст сам, и совпадение может не повторить точный
 * регистр или форму, в которой адрес уходил в `RCPT TO`), причём один
 * проход маскирует все адреса строки, а не только первый, и срабатывает
 * одинаково внутри и снаружи угловых скобок. Тем же порядком, что
 * в `OzonAccountBrokenLogger::redactedBodyOf()`, — **маскировка до
 * усечения**, а не после: совпадение может оказаться на границе длины,
 * и усечение первым обрезало бы его лишь частично.
 *
 * **«У компании нет ни одного участника» — тоже запись, а не исключение,
 * и это решение, а не недосмотр.** Раньше метод бросал `\RuntimeException`
 * до отправки. У исключения тот же путь наружу и тот же побочный эффект,
 * что у отказа `mailer->send()` выше: обработчик очереди упадёт с «not
 * found» на повторе, хотя подключение уже переведено в `broken` корректно.
 * Компания без единого участника структурно не существует (владелец
 * заводится вместе с компанией) — состояние теоретическое, и ронять
 * обработчик очереди ради теоретического случая не соразмерно риску.
 * При этом молчать нельзя: «письмо отправить некому» обязано остаться
 * видимым, иначе клиент решит, что письмо ушло, хотя получателей не было
 * вовсе. Решение — та же запись уровнем `warning`, с отдельным сообщением
 * и `recipients_count = 0`, без попытки отправки.
 */
final readonly class MailMarketplaceAccountBrokenNotifier implements MarketplaceAccountBrokenNotifier
{
    /**
     * Разумный запас на сообщение SMTP-транспорта: это одна строка ответа
     * сервера, не отчёт с данными. Больше — верный признак, что сообщение
     * не то, ради чего запись заводилась.
     */
    private const int MAX_EXCEPTION_MESSAGE_LENGTH = 500;

    /**
     * ASCII email — этого достаточно: цель не проверить, что подстрока
     * является валидным адресом (RFC 5322 разрешает намного больше), а не
     * пропустить обычный вид адреса, каким его мог вернуть SMTP-сервер.
     * Более строгое выражение легче обойти реальным ответом площадки же
     * мимо маскировки, а не строже защитить.
     */
    private const string EMAIL_LIKE_PATTERN = '/[a-zA-Z0-9.+_-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/';

    public function __construct(
        private CompanyMemberEmailsQuery $memberEmails,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
    ) {
    }

    public function accountBroken(string $companyId, MarketplaceAccount $account): void
    {
        $marketplaceAccountId = $account->id()->toRfc4122();
        $shop = $account->externalShopId();

        $recipients = $this->recipients($companyId);
        if ([] === $recipients) {
            // Компания без участников — состояние, которого в продукте нет
            // (владелец заводится вместе с компанией). Не исключение (см.
            // докблок класса): падение здесь после уже совершённого
            // перехода в broken сорвало бы обработчик очереди тем же
            // способом, что и отказ отправки ниже. Молчать всё равно
            // нельзя — фиксируем случай записью, а не пропуском.
            $this->log('warning', 'У компании нет ни одного участника — письмо о сломанном подключении отправить некому', $companyId, $marketplaceAccountId, $shop, 0);

            return;
        }

        // Название площадки — из самого подключения, не константой в тексте:
        // интерфейс общий, и со вторым коннектором письмо про Ozon ушло бы
        // клиенту Wildberries.
        $marketplace = ucfirst($account->marketplace()->value);

        try {
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
                        ."(Настройки → API-ключи) и замените его сами на экране\n"
                        ."«Подключения» в Conwix — займёт пару минут. Не получилось —\n"
                        ."напишите нам, поможем.\n\n"
                        ."Пока подключение не восстановлено, цифры в приложении\n"
                        ."остаются на дате последней успешной синхронизации.\n"
                    ),
            );
        } catch (\Throwable $failure) {
            $this->log(
                'warning',
                'Не удалось отправить письмо о сломанном подключении',
                $companyId,
                $marketplaceAccountId,
                $shop,
                \count($recipients),
                $failure,
            );

            return;
        }

        $this->log('warning', 'Письмо о сломанном подключении отправлено', $companyId, $marketplaceAccountId, $shop, \count($recipients));
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

    /**
     * Единая точка записи для всех трёх исходов (успех, отказ отправки,
     * нет получателей) — тем же приёмом, что у `OzonAccountBrokenLogger::log()`.
     * Известные адреса получателей (`$recipients`) в контекст не попадают
     * ни при каком исходе — только их количество: адрес — персональные
     * данные, восстанавливаются отдельным запросом по `company_id`, если
     * понадобятся. Сообщение исключения (путь отказа отправки) — отдельный
     * риск того же рода: транспорт формирует его сам и может эхом вернуть
     * туда адрес отклонённого получателя (см. докблок класса), поэтому оно
     * идёт в контекст только через `redactedExceptionMessage()`, а не сырым.
     */
    private function log(
        string $level,
        string $message,
        string $companyId,
        string $marketplaceAccountId,
        string $externalShopId,
        int $recipientsCount,
        ?\Throwable $failure = null,
    ): void {
        $context = [
            'company_id' => $companyId,
            'marketplace_account_id' => $marketplaceAccountId,
            'external_shop_id' => $externalShopId,
            'recipients_count' => $recipientsCount,
        ];

        if (null !== $failure) {
            $context['exception_class'] = $failure::class;
            $context['exception_message'] = $this->redactedExceptionMessage($failure);
        }

        try {
            $this->logger->log($level, $message, $context);
        } catch (\Throwable) {
            // Запись — диагностика, перевод подключения в broken —
            // поведение, которое уже совершено к моменту вызова.
            // Отказ самого журнала не имеет права всплыть здесь и сорвать
            // обработчик очереди тем же способом, каким это делал бы
            // необёрнутый отказ отправки письма (см. докблок класса).
        }
    }

    /**
     * Маскирует email-подобные подстроки ДО усечения, не после (см.
     * докблок класса): совпадение может оказаться на границе разумной
     * длины, и усечение первым обрезало бы его лишь частично, оставив
     * в записи остаток настоящего адреса. `preg_replace()` без лимита
     * заменяет все совпадения строки, а не только первое — типовой ответ
     * SMTP с несколькими отклонёнными адресами не оставит ни одного.
     */
    private function redactedExceptionMessage(\Throwable $failure): string
    {
        $message = preg_replace(self::EMAIL_LIKE_PATTERN, '[redacted]', $failure->getMessage());
        if (null === $message) {
            // preg_replace() возвращает null на отказе самого движка
            // регулярных выражений (например, невалидный UTF-8 во входной
            // строке) — недоказанный текст маскировки не безопаснее
            // отсутствия текста: отдаём заглушку, а не исходное сообщение
            // без вычищения.
            return '(сообщение недоступно)';
        }

        return mb_substr($message, 0, self::MAX_EXCEPTION_MESSAGE_LENGTH);
    }
}
