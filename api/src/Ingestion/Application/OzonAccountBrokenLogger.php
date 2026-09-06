<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

use Psr\Log\LoggerInterface;

/**
 * Единая запись журнала перед переводом Ozon-подключения в broken.
 *
 * Инцидент, ради которого класс появился: подключение ушло в broken через
 * две секунды после создания, а причину не удалось установить — очередь
 * отказов, сырьё, журналы контейнеров и трекер были пусты один за другим.
 * Обработчик перехватывает 401/403, переводит подключение в broken
 * и возвращает управление нормально: для очереди сообщение обработано
 * успешно, и не остаётся ни одного следа того, чем именно ответила
 * площадка (CLAUDE.md, «Наблюдаемость»).
 *
 * **Живёт в `Ingestion\Application`, а не в `Domain`.** Формирование записи
 * журнала — побочный эффект, а не правило домена: `OzonAuthorizationFailure`
 * (Domain) отличает отказ авторизации от прочих отказов, эта же логика
 * решает, что записать об уже классифицированном отказе, и делает то же,
 * что `ConnectOzonAccountAction::classifyProbeFailure()` — единственный
 * существующий пример записи о пробе ключей Ozon в этом модуле. Оба класса
 * теперь в одном слое (`IngestionApplication`), рядом с четырьмя
 * обработчиками, которые её вызывают, — доступ между ними не требует
 * никакого нового разрешения Deptrac.
 *
 * **Один класс, а не четыре одинаковых вызова логгера.** Четыре обработчика
 * (`FetchOzonCatalogHandler`, `FetchOzonPostingsHandler`,
 * `FetchOzonExpensesHandler`, `FetchOzonReturnsHandler`) вызывают
 * `IdentityFacade::markOzonAccountBroken()` из разных мест catch-блока,
 * и без общей точки форма записи (какие поля, как усечено тело, как
 * вычищен ключ) неизбежно расползлась бы при первой правке одного
 * из четырёх файлов.
 *
 * **`api_key` не попадает в запись ни в каком виде.** Он не заведён
 * в структуру ответа Ozon и в норме не встречается в теле, но тело
 * при отказе авторизации — это ровно то место, где площадка может
 * отразить присланные параметры запроса обратно (эхо в сообщении об
 * ошибке, отладочный дамп). `apiKey` передаётся сюда явно и вычищается
 * из тела до усечения: усечение обрезало бы совпадение, оказавшееся
 * за границей длины, и часть ключа могла бы остаться в записи.
 *
 * **`company_id` и `marketplace_account_id` — явные поля контекста,
 * не расчёт на `RequestContextProcessor`.** Тот процессор добавляет
 * `company_id` только внутри HTTP-запроса (его же докблок называет это
 * известным пробелом: ни один воркер до сих пор не писал в журнал).
 * Здесь — первая запись из воркера, и без явных полей отказ синхронизации
 * нечем было бы связать с компанией, а это ровно тот случай, ради
 * которого журнал читают.
 *
 * Уровень — `warning`, не `error`: отозванный или протухший ключ —
 * ожидаемое доменное условие жизненного цикла подключения (ADR-007),
 * а не наш дефект, и порог журнала в prod — `warning` (`monolog.yaml`),
 * так что запись доходит без изменения порога.
 *
 * **Сам вызов `$logger->warning()` тоже обёрнут в `catch (\Throwable)`,
 * той же причиной, что уже глушит её `statusCodeOf()` и `redactedBodyOf()`.**
 * Порядок в обработчике — сначала эта запись, потом
 * `IdentityFacade::markOzonAccountBroken()` — намеренный (запись должна
 * помнить оригинальное исключение, а не то, что осталось после разбора
 * ответа), и именно поэтому исключение из `warning()` не может остаться
 * непойманным: не перехваченное здесь, оно ушло бы из обработчика
 * наружу, сообщение отправилось бы в ретраи и осело в failed-очереди,
 * а подключение осталось бы `active` со сломанным ключом — ровно то,
 * что запрещает ADR-007 («молчаливая остановка синхронизации
 * запрещена»), и клиент не получил бы письма. Запись — диагностика,
 * перевод в `broken` — поведение; диагностика не имеет права ломать
 * наблюдаемое поведение. Потерянная запись журнала дешевле, чем
 * подключение, оставшееся `active` со сломанным ключом.
 *
 * Перехват стоит одним местом в `log()`, а не в каждом из четырёх
 * обработчиков: тот же аргумент, что у самого класса, — одна точка
 * не разойдётся при следующей правке, четыре — разойдутся.
 *
 * В боевой конфигурации сегодня единственный обработчик журнала —
 * `stream` в `php://stderr` (`monolog.yaml`), и он почти никогда
 * не бросает. Это свойство сегодняшней конфигурации, а не гарантия:
 * появится второй обработчик (сетевой, файловый с ротацией) —
 * и свойство исчезнет молча, если не защититься здесь заранее.
 */
final readonly class OzonAccountBrokenLogger
{
    /**
     * Разумный запас на JSON-тело отказа авторизации: у Ozon это короткое
     * `{"code":16,"message":"unauthenticated"}` или похожее, не отчёт
     * с данными. Больше — верный признак, что тело не то, ради чего
     * запись заводилась, и держать его целиком не нужно.
     */
    private const int MAX_BODY_LENGTH = 2000;

    private const string BODY_UNAVAILABLE = '(тело ответа недоступно)';

    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param 'products'|'sales'|'expenses'|'returns' $scope область
     *                                                       синхронизации — тот же словарь, что у проб ключей
     *                                                       (`ConnectOzonAccountAction::classifyProbeFailure()`)
     */
    public function log(
        string $companyId,
        string $marketplaceAccountId,
        string $scope,
        \Throwable $failure,
        string $apiKey,
    ): void {
        try {
            $this->logger->warning('Ozon отклонил авторизацию — подключение переводится в broken', [
                'scope' => $scope,
                'status_code' => $this->statusCodeOf($failure),
                'response_body' => $this->redactedBodyOf($failure, $apiKey),
                'company_id' => $companyId,
                'marketplace_account_id' => $marketplaceAccountId,
            ]);
        } catch (\Throwable) {
            // Запись — диагностика, перевод подключения в broken —
            // поведение (см. докблок класса): отказ самого журнала
            // не имеет права остановить обработчик до того, как он
            // дойдёт до markOzonAccountBroken().
        }
    }

    private function statusCodeOf(\Throwable $failure): ?int
    {
        // method_exists(), не instanceof HttpClientExceptionInterface:
        // getResponse() не входит в сам интерфейс исключений http-client
        // (у него его нет, есть только у TransportExceptionInterface
        // и подобных конкретных исключений) — тот же приём, что
        // в OzonAuthorizationFailure::isAuthorizationFailure().
        if (!method_exists($failure, 'getResponse')) {
            return null;
        }

        try {
            return $failure->getResponse()->getStatusCode();
        } catch (\Throwable) {
            return null;
        }
    }

    private function redactedBodyOf(\Throwable $failure, string $apiKey): string
    {
        if (!method_exists($failure, 'getResponse')) {
            return self::BODY_UNAVAILABLE;
        }

        try {
            // false — вернуть тело независимо от кода ответа; без него
            // getContent() бросает то же исключение повторно на 4xx/5xx.
            $body = $failure->getResponse()->getContent(false);
        } catch (\Throwable) {
            return self::BODY_UNAVAILABLE;
        }

        // Вычищаем ключ ДО усечения: совпадение может оказаться на границе
        // разумной длины, и усечение первым обрезало бы его лишь частично,
        // оставив в записи остаток настоящего значения.
        if ('' !== $apiKey) {
            $body = str_replace($apiKey, '[redacted]', $body);
        }

        return mb_substr($body, 0, self::MAX_BODY_LENGTH);
    }
}
