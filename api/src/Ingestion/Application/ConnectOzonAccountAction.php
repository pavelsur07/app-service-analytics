<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

use App\Identity\Application\Facade\IdentityFacade;
use App\Identity\Application\Facade\MarketplaceAccountConnectionOutcome;
use App\Ingestion\Application\Message\FetchOzonCatalogMessage;
use App\Ingestion\Application\Message\FetchOzonExpensesMessage;
use App\Ingestion\Application\Message\FetchOzonPostingsMessage;
use App\Ingestion\Application\Message\FetchOzonReturnsMessage;
use App\Ingestion\Domain\OzonAuthorizationFailure;
use App\Ingestion\Domain\OzonCatalogFetcher;
use App\Ingestion\Domain\OzonExpensesFetcher;
use App\Ingestion\Domain\OzonPostingsFetcher;
use App\Ingestion\Domain\OzonProductInfoFetcher;
use App\Ingestion\Domain\OzonProductListParser;
use App\Ingestion\Domain\OzonReturnsFetcher;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;

/**
 * Подключение кабинета Ozon при онбординге (ADR-021).
 *
 * Порядок важен и повторяет ReplaceOzonCredentialsAction: сохранить
 * непроверенный ключ значит завести подключение, которое станет broken
 * через несколько часов, — а клиент будет считать, что всё настроил.
 *
 * Живёт в Ingestion, хотя пишет данные Identity: проверка требует похода
 * в площадку, клиент площадки принадлежит Ingestion, а зависимости
 * строго вниз.
 *
 * **Проба покрывает пять эндпоинтов синхронизации, а не один-четыре.**
 * Инцидент на проде: ключ прошёл `/v3/product/list`, подключение
 * создалось активным, а первая же реальная синхронизация упала
 * на финансовом эндпоинте — в Ozon права на финансы выдаются отдельно
 * от товарных. Поэтому пробы идут последовательно, до первого отказа,
 * по каждой нужной синхронизации области:
 *
 * 1. Товары — `/v3/product/list` (`OzonCatalogFetcher`).
 * 2. Карточки товаров — `/v3/product/info/list` (`OzonProductInfoFetcher`),
 *    сразу после товаров, тем же product_id.
 * 3. Продажи — `/v2/posting/fbo/list` (`OzonPostingsFetcher`).
 * 4. Расходы — `/v1/finance/accrual/by-day` (`OzonExpensesFetcher`).
 * 5. Возвраты — `/v1/returns/list` (`OzonReturnsFetcher`).
 *
 * **Карточки товаров пробуются своим запросом, а не выводятся из товарной
 * пробы.** Раньше здесь стояло допущение «это одна область прав с
 * `/v3/product/list`, отдельная проба не нужна» — то самое допущение,
 * которое уже подвело один раз на продажах и финансах (см. выше). Ни
 * документация Ozon, ни опыт этого продукта его не подтверждали;
 * единственная причина, по которой оно жило, — не путаница с product_id,
 * а нежелание её решать. Решение: страница товаров запрашивается с
 * `PROBE_LIMIT = 1`, и `OzonProductListParser::parse()` (тот же парсер,
 * что у `FetchOzonCatalogHandler`) отдаёт не больше одного `product_id`
 * с этой страницы — валидный непустой список для `/v3/product/info/list`
 * без эвристик по телу ответа.
 *
 * **У продавца без единого товара проба карточек пропускается**, а не
 * подставляет фиктивный `product_id`. Эндпоинт требует непустой список
 * (интерфейс `OzonProductInfoFetcher::fetchNames()`), и пустой запрос
 * получил бы 400 — ошибку параметров, а не авторизации, которую конструкция
 * «найти отказ права» classifyProbeFailure отличить от настоящего отказа
 * не может и не должна. Пропуск честен вдвойне: раз каталог пуст, нечему
 * подтверждать право, и синхронизации нечего будет грузить этим тиком —
 * тот же приём, которым `FetchOzonCatalogHandler::fetchProductInfo()`
 * уже решает эту же задачу на каждом тике синхронизации.
 *
 * Каждая проба идёт своим последовательным запросом (не параллельно):
 * первый же отказ решает исход, и параллельные запросы тратили бы квоту
 * подключения (ADR-006) на пробы, ответ которых уже не нужен.
 * У проб продаж, расходов и возвратов — минимально возможное окно
 * и лимит: это проверка права, а не загрузка данных.
 *
 * Проба различает три ветви отказа на каждом шаге, а не две, — по тому,
 * кто должен узнать об отказе и как:
 *
 * 1. `OzonAuthorizationFailure` (401/403) — ключ отклонён самой площадкой
 *    для конкретной области. Клиенту нужно другое действие (включить
 *    право и перевыпустить ключ), поэтому это отдельный исход `Rejected*`
 *    со своим текстом на каждую область.
 * 2. Любой другой отказ HTTP-клиента (сеть, таймаут, лимит запросов,
 *    прочие 4xx и 5xx) — площадка не ответила по причине, которая лечится
 *    повтором, а не новым ключом. `Unavailable` — ожидаемое доменное
 *    условие уровня warning, не наша ошибка, и в трекер намеренно
 *    не идёт: обработчика Sentry в конфиге журнала нет (CLAUDE.md,
 *    «Наблюдаемость»), и топить в нём такие случаи означало бы прятать
 *    в шуме настоящие ошибки.
 * 3. Всё остальное (`TypeError`, `Error`, `LogicException` нашего кода) —
 *    не отказ площадки, а наш дефект. Спрятать его под «Ozon недоступен»
 *    значило бы навсегда лишить себя шанса его увидеть: клиент решит,
 *    что дело в площадке, а трекер об этом не узнает никогда. Поэтому
 *    эта ветвь пробрасывается, а не превращается в исход.
 */
final readonly class ConnectOzonAccountAction
{
    private const int PROBE_LIMIT = 1;

    public function __construct(
        private OzonCatalogFetcher $catalogFetcher,
        private OzonProductListParser $catalogParser,
        private OzonProductInfoFetcher $productInfoFetcher,
        private OzonPostingsFetcher $postingsFetcher,
        private OzonExpensesFetcher $expensesFetcher,
        private OzonReturnsFetcher $returnsFetcher,
        private IdentityFacade $identityFacade,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(
        string $companyId,
        string $name,
        string $clientId,
        string $apiKey,
        string $actorUserId,
    ): ConnectOzonAccountOutcome {
        $failure = $this->probeAllScopes($clientId, $apiKey);
        if (null !== $failure) {
            return ConnectOzonAccountOutcome::failed($failure);
        }

        $connection = $this->identityFacade->connectOzonAccount($companyId, $name, $clientId, $apiKey, $actorUserId);
        if (MarketplaceAccountConnectionOutcome::AlreadyConnected === $connection->outcome) {
            return ConnectOzonAccountOutcome::failed(ConnectOzonAccountResult::AlreadyConnected);
        }

        if (null === $connection->accountId) {
            // `assert()` не годится здесь: в боевой конфигурации
            // `zend.assertions=-1` компилирует его прочь, и проверка
            // не выполняется вовсе. Настоящая проверка нужна, чтобы
            // нарушенный инвариант дошёл до трекера как наш дефект,
            // а не привёл к TypeError чуть ниже на передаче null
            // в scheduleInitialBackfill(string $accountId).
            throw new \LogicException('Нарушен инвариант: исход подключения Connected обязан нести accountId (MarketplaceAccountConnection::connected()), а пришёл пустым.');
        }
        $this->scheduleInitialBackfill($companyId, $connection->accountId);

        return ConnectOzonAccountOutcome::connected($connection->accountId);
    }

    /**
     * Последовательные пробы до первого отказа. `null` означает, что все
     * пять эндпоинтов подтверждены и можно сохранять подключение.
     */
    private function probeAllScopes(string $clientId, string $apiKey): ?ConnectOzonAccountResult
    {
        $now = new \DateTimeImmutable();
        // Минимально возможное окно — проверка права, а не загрузка данных
        // (квота подключения не бесплатна, ADR-006).
        $probeSince = $now->modify('-1 minute');
        $probeDay = $now;

        try {
            $catalogBody = $this->catalogFetcher->fetchPage($clientId, $apiKey, '', self::PROBE_LIMIT);
        } catch (\Throwable $failure) {
            return $this->classifyProbeFailure($failure, ConnectOzonAccountResult::Rejected, $clientId, 'products');
        }

        // Разбор — тем же парсером, что и настоящая синхронизация каталога
        // (FetchOzonCatalogHandler). Ошибка разбора не отказ права и не
        // недоступность площадки — она обязана пробрасываться неизменённой,
        // и здесь ей ничто не мешает: catch выше уже закрыт, эта строка вне
        // его блока.
        $productIds = $this->catalogParser->parse($catalogBody)->productIds();
        if ([] !== $productIds) {
            // PROBE_LIMIT = 1 держит страницу максимум в один товар —
            // список ниже никогда не превышает лимит пробы, который
            // задача просила не увеличивать.
            try {
                $this->productInfoFetcher->fetchNames($clientId, $apiKey, $productIds);
            } catch (\Throwable $failure) {
                return $this->classifyProbeFailure($failure, ConnectOzonAccountResult::RejectedProductInfo, $clientId, 'product_info');
            }
        }
        // [] === $productIds: у продавца нет ни одного товара — пробовать
        // право на карточки нечем (эндпоинт отвергает пустой список 400,
        // не авторизацией), и синхронизации самого каталога в этом случае
        // тоже нечего будет грузить (FetchOzonCatalogHandler::fetchProductInfo
        // пропускает запрос по той же причине). Пропуск — не дырка в пробе,
        // а честный ответ: продавец без товаров не должен упереться
        // в невозможность подключиться вовсе.

        try {
            $this->postingsFetcher->fetch($clientId, $apiKey, $probeSince, $now);
        } catch (\Throwable $failure) {
            return $this->classifyProbeFailure($failure, ConnectOzonAccountResult::RejectedSales, $clientId, 'sales');
        }

        try {
            $this->expensesFetcher->fetchDay($clientId, $apiKey, $probeDay, '');
        } catch (\Throwable $failure) {
            return $this->classifyProbeFailure($failure, ConnectOzonAccountResult::RejectedExpenses, $clientId, 'expenses');
        }

        try {
            $this->returnsFetcher->fetchPage($clientId, $apiKey, $probeSince, $now, 0, self::PROBE_LIMIT);
        } catch (\Throwable $failure) {
            return $this->classifyProbeFailure($failure, ConnectOzonAccountResult::RejectedReturns, $clientId, 'returns');
        }

        return null;
    }

    /**
     * Общая ветвь для каждой из пяти проб: которая из них не прошла
     * решает вызывающий метод (передаёт свой `$rejectedResult` и имя
     * области для журнала), а не эта функция.
     */
    private function classifyProbeFailure(
        \Throwable $failure,
        ConnectOzonAccountResult $rejectedResult,
        string $clientId,
        string $scope,
    ): ConnectOzonAccountResult {
        if (OzonAuthorizationFailure::isAuthorizationFailure($failure)) {
            return $rejectedResult;
        }

        if (!$failure instanceof HttpClientExceptionInterface) {
            // Не «площадка недоступна» — наш дефект (опечатка в коде,
            // отсутствующий метод, нарушенный инвариант). Он обязан
            // выглядеть как наш: дойти до трекера и стать 500,
            // а не спрятаться под благополучным на вид исходом.
            throw $failure;
        }

        // Лимит запросов, сбой площадки, обрыв сети, прочие отказы
        // HTTP-клиента. В отличие от замены ключей, недоступность
        // здесь не пробрасывается исключением: ADR-021 требует именно
        // различимых исходов, а 500 не может честно сказать
        // «попробуйте позже». api_key в журнал не попадает ни в каком
        // виде; request_id и company_id добавляет RequestContextProcessor.
        $this->logger->warning('Ozon не ответил при проверке ключей подключения', [
            'client_id' => $clientId,
            'scope' => $scope,
        ]);

        return ConnectOzonAccountResult::Unavailable;
    }

    /**
     * Ступень 1 (ADR-021): текущий месяц, сразу, вперёд остальных.
     * Каталог — снимок текущего состояния, глубины у него нет, поэтому
     * одним сообщением.
     */
    private function scheduleInitialBackfill(string $companyId, string $accountId): void
    {
        $this->bus->dispatch(new FetchOzonCatalogMessage($companyId, $accountId));

        $businessDates = InitialBackfillWindow::businessDates(new \DateTimeImmutable());
        foreach ($businessDates as $businessDate) {
            $this->bus->dispatch(new FetchOzonPostingsMessage($companyId, $accountId, $businessDate));
            $this->bus->dispatch(new FetchOzonExpensesMessage($companyId, $accountId, $businessDate));
        }

        // Возвраты принимают диапазон, а не один день (FetchOzonReturnsMessage:
        // from/to), поэтому уходят одним сообщением на весь месяц. Тридцать
        // сообщений с диапазоном в один день отработали бы, но потратили бы
        // квоту площадки тридцатикратно.
        $first = $businessDates[0] ?? null;
        $last = $businessDates[\count($businessDates) - 1] ?? null;
        if (null !== $first && null !== $last) {
            $this->bus->dispatch(new FetchOzonReturnsMessage($companyId, $accountId, $first, $last));
        }
    }
}
