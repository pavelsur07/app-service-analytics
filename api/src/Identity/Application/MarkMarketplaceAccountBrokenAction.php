<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\MarketplaceAccountBrokenNotifier;
use App\Identity\Domain\MarketplaceAccountRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Площадка отказала в авторизации: подключение переводится в broken,
 * клиент получает письмо (ADR-007).
 *
 * Синхронизация после этого останавливается сама — планировщик перечисляет
 * только активные подключения. Именно поэтому переход обязан порождать
 * письмо: иначе получилась бы молчаливая остановка синхронизации, прямо
 * запрещённая CLAUDE.md, и клиент продолжал бы смотреть на вчерашние
 * цифры как на сегодняшние.
 *
 * Письмо отправляет тот вызов, который состояние действительно поменял:
 * условие «было active» живёт внутри UPDATE, и второй одновременный отказ
 * (у подключения две задачи в очереди — продажи и каталог) письма
 * не породит.
 *
 * **Пропажа подключения между переводом в broken и чтением для уведомления
 * (дефект 3, третье и последнее место той же формы, что закрыта
 * в `OzonAccountBrokenLogger` и `MailMarketplaceAccountBrokenNotifier`).**
 * `markBrokenIfActive()` уже изменил и закоммитил состояние — откатывать
 * здесь нечего, отменять нечего. Раньше `get()`, вернувший `null`, считался
 * теоретической гонкой и бросал исключение; сегодня это не так:
 * `DiscardUnusedConnectionAction` (`Ingestion\Application`) умеет удалять
 * подключение целиком (`DELETE /api/companies/{companyId}/connections/{id}`),
 * и строка, только что обновлённая этим методом, может быть удалена другим
 * запросом до следующего чтения. Исключение отсюда ушло бы из обработчика
 * очереди наружу, сообщение уехало бы в ретраи, а на повторе
 * `markBrokenIfActive()` вернёт `false` («уже не active») — обработчик
 * (например, `FetchOzonPostingsHandler::findOzonSyncTarget()`) упадёт
 * с бессмысленным «not found», а настоящая причина (отказ авторизации)
 * потеряется. Ровно это наблюдалось в бою: десять сообщений «not found»
 * и ни следа причины. Поэтому запись уровнем `warning` вместо исключения —
 * тем же приёмом, что в двух предыдущих местах.
 *
 * **Возвращает `true`.** Булев результат метода документирован и проверен
 * тестами как «состояние действительно изменено этим вызовом», а не «клиент
 * уведомлён» — `MarkMarketplaceAccountBrokenActionTest::
 * testRealNotifierSendFailureDoesNotUndoTheBrokenTransitionOrEscapeTheAction()`
 * уже утверждает `assertTrue($changed)` при отказе реальной отправки письма.
 * Единственный вызывающий — `IdentityFacade::markOzonAccountBroken()`,
 * а его вызывающие (`FetchOzonPostingsHandler`, `FetchOzonCatalogHandler`,
 * `FetchOzonExpensesHandler`, `FetchOzonReturnsHandler`) значение вовсе
 * не читают: они зовут метод и сразу `return;`. Возвращать `false` здесь
 * значило бы соврать о самом факте перехода — он уже произошёл и закоммичен
 * до этой строки — ради события (не удалось уведомить), у которого и так
 * есть собственный канал сигнала: запись журнала.
 *
 * **Логгер внедряется прямо в этот класс, не выносится в отдельный сервис.**
 * `OzonAccountBrokenLogger` — отдельный класс потому, что запись вызывают
 * четыре разных обработчика Ingestion и без общей точки формат записи
 * разошёлся бы при первой правке одного из них. Здесь ровно один вызывающий
 * и ровно одна точка вызова внутри метода — тот же случай, что у
 * `MailMarketplaceAccountBrokenNotifier`, который ведёт запись как приватный
 * метод самого себя, а не отдельным классом. `Psr\Log\LoggerInterface` —
 * не часть домена ни одного модуля и не входит ни в один коллектор
 * `deptrac.php`, поэтому инъекция в `Identity\Application` не требует
 * новой границы, как её не потребовала точно такая же инъекция в
 * `Ingestion\Application` (`OzonAccountBrokenLogger`) и в
 * `Identity\Infrastructure` (`MailMarketplaceAccountBrokenNotifier`).
 */
final readonly class MarkMarketplaceAccountBrokenAction
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

        if (!$this->accounts->markBrokenIfActive($companyId, $id)) {
            return false;
        }

        $account = $this->accounts->get($companyId, $id);
        if (null === $account) {
            // Состояние уже переведено в broken и закоммичено — уведомлять
            // некого, но не переставать быть видимым (см. докблок класса).
            $this->logDisappearance($companyId, $marketplaceAccountId);

            return true;
        }

        $this->notifier->accountBroken($companyId, $account);

        return true;
    }

    /**
     * Уровень `warning`, не `error` — тем же основанием, что у
     * `OzonAccountBrokenLogger` и `MailMarketplaceAccountBrokenNotifier`:
     * порог журнала в prod равен `warning` (`monolog.yaml`, `when@prod`),
     * `info` туда не попал бы вовсе, а само событие — не наш дефект,
     * а ожидаемое (пусть и редкое) следствие удаления подключения клиентом,
     * гонка с которым описана в докблоке класса.
     *
     * В контексте — `company_id` и идентификатор подключения, и то, что
     * состояние уже переведено в broken, а уведомление отправить не удалось
     * (само сообщение записи это называет). Секретов и персональных данных
     * здесь нет и не может быть: удалённая строка ничего не сообщает
     * о содержимом, кроме своего бывшего идентификатора.
     *
     * Обёрнуто в `catch (\Throwable)` тем же приёмом и по тому же основанию,
     * что `OzonAccountBrokenLogger::log()` и `MailMarketplaceAccountBrokenNotifier::log()`:
     * запись — диагностика, переход в broken — уже совершённое поведение,
     * и отказ самого журнала не имеет права всплыть здесь и повторить
     * ровно тот дефект, который этот метод и закрывает.
     */
    private function logDisappearance(string $companyId, string $marketplaceAccountId): void
    {
        try {
            $this->logger->warning('Подключение переведено в broken, но исчезло до отправки уведомления', [
                'company_id' => $companyId,
                'marketplace_account_id' => $marketplaceAccountId,
            ]);
        } catch (\Throwable) {
            // Запись — диагностика, переход в broken — уже совершённое
            // и закоммиченное поведение; отказ журнала не должен всплыть
            // здесь и превратиться в то же самое «not found» на ретрае.
        }
    }
}
