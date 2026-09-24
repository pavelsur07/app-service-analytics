# План упорядочения `api/src`

> **Для исполнителя:** выполнять пакеты последовательно в ветках задач. При реализации использовать `superpowers:executing-plans`; независимое ревью проекта обязательно и не заменяется самопроверкой.

**Цель:** сократить переполненные каталоги `Ingestion` и `Identity`, сохранив существующие модули, слои, зависимости и поведение API.

**Архитектура:** верхний уровень `Shared`, `Identity`, `Ingestion`, `PriceMonitoring`, `Links` и слои `Domain`, `Application`, `Infrastructure`, `Ui` остаются. Внутри больших слоёв файлы группируются по предметным сценариям. Каждый пакет представляет собой перенос классов с обновлением namespace и ссылок на них; изменение логики в пакет не входит.

**Стек:** PHP 8.4, Symfony 7.4, Doctrine, Deptrac, PHPUnit, OpenAPI/Nelmio.

**Spec:** `docs/structure.md` → «api/ — Symfony», «Внутри модуля»; предложение по структуре в разговоре от 24.09.2026. Ограничения — `CLAUDE.md` → «Модули» и «Процесс работы над задачей», `docs/patterns.md` → «Куда положить код».

## Общие ограничения

- Не создавать модуль, Symfony Bundle, Facade, зависимость, миграцию или новый API-контракт.
- Не перемещать сущности `Domain` в первой серии переносов: маппинг Doctrine и связанные правила проверяются отдельно, если появится реальная потребность.
- Не менять SQL, денежные расчёты, сериализованные поля, маршруты, права доступа и конфигурацию очереди.
- Не расширять слои Deptrac. Исключения для межарендаторных операций должны остаться точечными и соответствовать новым FQCN. Перенос защищённого класса и правка его правил выполняются в одном коммите.
- `api/config/services.yaml`, `api/config/packages/security.yaml`, сообщения Messenger и ссылки на `::class` проверяются при каждом переносе; изменение строковых service ID или маршрутизации сообщений не предполагается.
- `docs/structure.md` обновляется в том же коммите, что и каталоги. `docs/patterns.md` менять только при изменении правила размещения; этот план такого изменения не предполагает.
- Не писать тесты, которые лишь сравнивают старый и новый namespace. Использовать существующие функциональные и интеграционные сценарии; добавить тест только при обнаружении непокрытого поведения или пробела в проверке границы доступа.

## Целевое дерево

```text
Ingestion/
├── Domain/                         # существующий состав
├── Application/
│   ├── Facade/                     # существующий публичный контракт
│   ├── Message/                    # существующие сообщения
│   ├── MessageHandler/             # существующие обработчики
│   ├── Buyout/                     # отчёт и временной ряд выкупа
│   ├── UnitEconomics/              # расчёт и DTO отчёта
│   └── ListingCosts/               # сценарии себестоимости
├── Infrastructure/
│   ├── Connector/Ozon/             # существующий адаптер площадки
│   ├── Persistence/                # существующие записи
│   └── Query/
│       ├── Buyout/
│       ├── UnitEconomics/
│       ├── ListingCosts/
│       ├── Listings/
│       └── Sales/
└── Ui/
    ├── Command/                   # существующие команды
    ├── Controller/                # существующие контроллеры
    ├── Request/                   # существующие запросы
    └── Response/
        ├── Buyout/
        ├── UnitEconomics/
        ├── ListingCosts/
        ├── Listings/
        ├── Sales/
        └── Connections/

Identity/
├── Domain/                         # существующий состав
├── Application/                    # существующий состав, включая Facade/
├── Infrastructure/                 # существующий состав
└── Ui/
    ├── Controller/
    │   ├── Admin/
    │   ├── Registration/
    │   ├── Extension/
    │   └── Session/
    ├── Response/                   # аналогичные группы по потребителю
    └── Security/                   # существующий состав
```

Группы создаются только для файлов из пакета; пустых каталогов нет. `RecentlyIngestedAccountsQuery` и связанные операционные классы остаются на текущих местах. В `Identity` защищённые запросы и security-классы остаются на текущих местах: группировка HTTP-входов сама по себе уже решает навигационную проблему и не размывает границу доверия.

## Пакет 1 — `Ingestion`: запросы и ответы

**Результат:** `Infrastructure/Query` больше не содержит вместе запросы выкупа, юнит-экономики, себестоимости и продаж; соответствующие HTTP DTO сгруппированы по тем же сценариям. Пакет можно выпустить самостоятельно.

**Файлы:** переносы в `api/src/Ingestion/Infrastructure/Query/**` и `api/src/Ingestion/Ui/Response/**`; ссылки в `api/src/Ingestion/**`, `api/tests/**`; `docs/structure.md`. `api/deptrac.php` проверяется и меняется только если перенос затронул точное правило.

**Карта переносов:**

| Группа | Классы `Infrastructure/Query` | DTO `Ui/Response` |
|---|---|---|
| `Buyout` | `Buyout*`, `UnclassifiedOzonBuyout*`, `OzonPostingRawHistory*` | `Buyout*` |
| `UnitEconomics` | `UnitEconomics*`, `ExpenseCoverageQuery` | `UnitEconomics*` |
| `ListingCosts` | `ListingCost*` | `ListingCost*` |
| `Listings` | `ListingSnapshot*`, `CompanySku*` | `CompanySkuListResponse` |
| `Sales` | `SalesFactList*`, `SkuSalesSummary*` | `SalesFactList*`, `SkuSales*` |
| `Connections` | не переносить запросы в этом пакете | `ConnectedAccountResponse`, `ConnectionResponse`, `ConnectionsResponse`, `ReplacedCredentialsResponse` |

`AccountFreshness*`, `RecentlyIngestedAccount*` и прочие классы, не названные в таблице, остаются в корне `Query` до отдельного предметного изменения. Перед переносом проверить, что каждый перечисленный класс используется в соответствующем сценарии; спорный класс оставить на месте и указать причину в отчёте.

- [ ] Снять исходное состояние: ветка, `git status`, diff, список файлов пакета; выполнить `make lint stan deptrac structure-check` и затронутые `make test-unit test-int test-func`. Ошибки исходной ветки записать отдельно.
- [ ] Перенести группу `Buyout`, обновить `namespace`, `use`, FQCN в тестах и конфигурации; выполнить `make lint stan deptrac structure-check` и тесты выкупа из `test-int`/`test-func`.
- [ ] Перенести `UnitEconomics`, `ListingCosts`, `Listings`, `Sales`, `Connections` по одной группе за коммит. В каждом таком коммите обновлять `docs/structure.md` и ссылки на FQCN; после группы запускать статические проверки и затронутые тесты. Не смешивать исправления логики с переносом.
- [ ] Убедиться, что итоговый `docs/structure.md` содержит конкретную карту папок и правило: подпапка появляется для устойчивой предметной группы, а не для одного файла.
- [ ] Проверить `make api-types-check`: перемещение response-классов не должно менять опубликованную OpenAPI-схему и сгенерированные TS-типы. Если схема меняется, установить причину и исправить перенос до ревью.
- [ ] Выполнить `make ci-local` либо подтвердить зелёный обязательный CI для всего diff ветки. Собрать предметный пакет ревью по `docs/review-package-template.md`, включая все перенесённые файлы и конфигурацию; пройти Claude и, для финансовых/критических участков Ingestion, оба прохода Codex.

**Критерии:** все ранее доступные маршруты, схемы ответов и результаты тестов неизменны; Deptrac не получил широких новых разрешений; новый файл соответствующего сценария имеет однозначное место.

## Пакет 2 — `Ingestion`: сценарии Application

**Результат:** после стабилизации пакета 1 сгруппировать сценарии, если корень `Application` остаётся неудобным. Пакет также независим от `Identity`.

**Файлы:** `api/src/Ingestion/Application/{BuildBuyout*,Buyout*,BuildUnitEconomics*,UnitEconomics*,*ListingCost*}.php`, их потребители и тесты, `docs/structure.md`. `Facade/`, `Message/`, `MessageHandler/`, `DispatchActiveOzonSyncsAction`, `NotifyStaleAccountsAction` не перемещать.

- [ ] Перенести `BuildBuyoutDailySeriesAction`, `BuildBuyoutRateReportAction`, `BuyoutRateReport`, `BuyoutRateSku`, `BuyoutRateSummary` в `Application/Buyout` и обновить все FQCN.
- [ ] Перенести `BuildUnitEconomicsAction`, `UnitEconomicsExpense`, `UnitEconomicsReport`, `UnitEconomicsSku` в `Application/UnitEconomics` и обновить все FQCN.
- [ ] Перенести `CorrectListingCostAction`, `ListListingCostsAction`, `ListingCostsPage`, `SetListingCostAction` в `Application/ListingCosts` и обновить все FQCN.
- [ ] Проверить `api/deptrac.php`: новые подпапки должны попадать в прежний `IngestionApplication`, а два защищённых action — оставаться только в своих узких слоях. Запустить `make lint stan deptrac structure-check`, затронутые тесты и `make api-types-check`.
- [ ] Обновить `docs/structure.md`, пройти CI и предметное ревью как в пакете 1. Денежные сценарии оценивать по высокому порогу ревью даже при неизменной арифметике.

**Критерии:** сигнатуры сценариев и Facade, значения отчётов и границы Deptrac сохраняются.

## Пакет 3 — `Identity`: HTTP-входы и DTO

**Результат:** разделить 14 контроллеров и 12 response-классов по потребителю, не трогая домен, репозитории, токены и проверку прав.

**Файлы:** `api/src/Identity/Ui/Controller/**`, `api/src/Identity/Ui/Response/**`, их ссылки и тесты, `api/deptrac.php`, `docs/structure.md`. При наличии точных service ID проверить `api/config/packages/security.yaml` и `api/config/services.yaml`.

**Карта контроллеров:** `Admin/*` — `AdminLogin`, `AdminMe`, `CreateAdministrator`, `ListClientAccounts`, `SetClientAccountStatus`; `Registration/*` — `SelfRegistration`, `RegisterClientAccount`, `ResendEmailVerification`, `ConfirmEmail`; `Extension/*` — `ExtensionMe`, `IssueExtensionToken`, `RevokeExtensionToken`; `Session/*` — `Login`, `Me`. DTO ответов распределить по фактическому контроллеру-потребителю; совместно используемый DTO оставить в корне `Response`.

- [ ] Зафиксировать исходные функциональные тесты регистрации, admin, seller и extension, включая отказ в доступе вне области токена и межарендаторные сценарии.
- [ ] Перенести группы `Session`, `Extension`, `Registration` по одной, обновляя FQCN и точные правила `IdentityEmailVerificationUi` в `api/deptrac.php` в том же коммите, что и `Registration`.
- [ ] Перенести `Admin` и в том же коммите обновить точное правило `IdentityAdminAccountsUi` и исключение из широкого `IdentityUi`. Не заменять точное правило разрешением на всю папку `Admin`.
- [ ] Разложить response DTO по фактическому потребителю; проверить `make api-types-check`, чтобы схема OpenAPI осталась идентичной.
- [ ] Обновить `docs/structure.md`; выполнить `make lint stan deptrac structure-check`, затронутые `make test-unit test-int test-func`, затем обязательный CI и предметное ревью Claude + два прохода Codex из-за аутентификации и изоляции.

**Критерии:** маршруты, ответы и доступы неизменны; `ListClientAccountsController`, `ConfirmEmailController` и `ResendEmailVerificationController` остаются в точечных слоях Deptrac и исключены из широкого `IdentityUi`; запрос по токену расширения не становится доступен общему HTTP-слою.

## Контроль после серии

- [ ] Сверить `git diff --find-renames` и состав всех ревью-пакетов с файлами задач; не включить чужие изменения.
- [ ] Для каждого пакета записать команды и фактические результаты, хэши/пути заключений ревью, принятые и отклонённые замечания. После исправлений повторить затронутые проверки и ревью изменённых частей.
- [ ] Перед merge убедиться в зелёном обязательном CI для полного diff. При красном CI либо незавершённом обязательном ревью не выполнять merge.

## Осознанно вне серии

`Domain` и Doctrine mapping, операционные запросы со специальными правами, `Infrastructure/Security`, очереди, SQL, денежная арифметика и публичный контракт API. Для каждого из них нужен собственный предметный повод, а не перенос ради одинаковой глубины дерева.
