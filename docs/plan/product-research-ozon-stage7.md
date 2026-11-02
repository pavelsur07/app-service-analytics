# product-research-ozon — этап 7: интерфейс seller

Дата: 02.11.2026. Статус: реализация. Основание: ADR-050, принятый этап 6.

## Цель и границы

Продавец создаёт сезонный проект из категории и диапазонов календарных месяцев,
видит предпросмотр, запускает загрузку, наблюдает месяцы, добавляет периоды,
повторяет неудачную загрузку и копирует проект. UI работает через Symfony;
Python/MPStats, production activation и реальные ключи остаются этапом 8.
Существующий gate компании/подключений ADR-021 сохраняется.
Новых библиотек, Facade, очередей, аналитических таблиц и миграций нет.

## 7.1. Контракт для пользовательского пути

- До этой задачи consumer research API во frontend отсутствует. Дополнения
  совместимы: preview.cachedMonthCount (nullable, ориентир по локальным ready
  проекциям той же company/source/category/точным closed датам); frozen v1/v2
  межсервисные контракты не меняются.
- Новый company-scoped GET availability возвращает существующий серверный флаг,
  без обращения к Python; нет второго frontend-флага. Навигация скрыта при false,
  direct route объясняет недоступность. Auth/membership действуют и для availability.
- Новый GET projects/{id}/progress: status/version/lastRunId/runRevision и до24
  требований с latest matching attempt, origin/collectedAt/usedAt/rows/error.
  Один MVCC SQL, явный companyId, limit, без entity hydration/N+1. Несовпадающий
  старый partial не считается готовым; отсутствующие данные имеют status missing.
  Возврат в проект восстанавливает прогресс без browser storage/run ID из памяти.
- Типы только из OpenAPI: источник → api-doc-export/api-types/api-types-check.

## 7.2. Список и создание

- Feature product-research-ozon: model (Query/API), lib (чистые правила), ui.
  URL /companies/:companyId/product-research и /:projectId; keyset список с лимитом.
- Поиск категории после 3 Unicode-букв, debounce, полный путь, только явный выбор;
  изменение текста снимает выбор. Пустой результат, pending и 503 различаются.
- Диапазоны месяцев и названия сезонов, + период, 3/6/12 закрытых месяцев,
  тот же период год назад. Календарь/пересечения/лимит/summary/name определяет
  серверный preview. Текущий месяц явно до вчера, будущий недоступен.
- FBO+FBS и RUB фиксированы MVP и показаны. Название можно заменить, комментарий
  свободный. Сохранение требует актуального preview того же ввода; старые ответы
  другого набора параметров/компании не дают разрешения сохранить.
- Неизвестный исход create не превращается в успех и не повторяется автоматически:
  предложение проверить список проектов перед новым созданием.

## 7.3. Проект, загрузка и восстановление

- Читается локальный progress всех frozen требований, включая готовые месяцы
  предыдущих запусков. Категория/FBS readonly; copy создаёт новый draft.
- Добавление периодов не меняет уже принятый run; preview включает объединение.
  Повтор загрузки выбирает missing/failed closed, partial всегда новый (этапы5/6).
- Повторные нажатия блокируются. Для неизвестного исхода start сохраняются
  UUIDv7 requestId и исходные version/calendarDate; повтор отправляет тот же intent.
- Poll только loading, каждые5с, без background polling, максимум10мин за сессию,
  остановка при terminal/error; ручное продолжение. Задержка прогресса/ошибка
  не маскируются готовностью, старые данные явно помечены. 0 строк — предупреждение.
- Company client/queryKey обязательны, без placeholder предыдущей компании,
  без localStorage и прямого fetch. Примитивы/токены packages/ui; responsive и keyboard.

## 7.4. TDD, ревью и доставка

Vitest node + типизированный MSW: чистые правила, ключи/разделение компаний,
ошибки и intent/polling. Playwright проверяет реальный seller UI/маршрутизацию
с fixture-only research API (типизированные тела из OpenAPI), включая сетевые
сбои/потерю ответа; общий login/company gate — настоящие. Это UI E2E, не доказательство
новой реальной интеграции MPStats; PHP functional/integration проверяют server
scope/progress/estimate, прежний межсервисный smoke остаётся в CI.
Production флаг false, внешних вызовов MPStats нет.

Обязательные seller gates/type freshness, затронутые PHP проверки, полный CI,
независимые свежие CLI5A/5B при отсутствии Claude, разбор и повтор исправлений,
PR/merge/deploy/production smoke, постоянные отчёты. Только seller UI меняется;
общие packages/ui не изменяются, admin/extension проверяются обязательным CI.

## Матрица приёмки

| ID | Поведение / тест |
|---|---|
| B01 | Availability flag false/true, anonymous401/foreign403; no Python |
| B02 | Progress <=24, company/source scope, exact frozen dates, missing/partial/history/lastRun |
| B03 | Cache estimate closed only, samecompany/source/category/dates, null без source |
| U01 | Выбор категории снимается вводом; поиск от3 букв; запрос/ошибка/cursor company scoped |
| U02 | 3/6/12/прошлый год/пересечения/current/future; актуальность preview |
| U03 | UUIDv7, один intent при повторах/потере ответа; 409 и create unknown без false success |
| U04 | Poll bounded, terminal/error stop, ручной resume, stale/progress/error отображаются |
| E01 | Seller создать сезонный draft → load → ready, reopen, 0 rows/даты/источник |
| E02 | Частичный сбой → failed-only retry → ready; ready история видна |
| E03 | Добавить период при active → partial → догрузить missing; copy draft/category readonly |
| E04 | Смена company не показывает старые формы/проекты/каталог/progress, disabled route/nav |
| E05 | Credential error понятен, клавиатура/узкий экран, нет секретных полей |
| G01 | Red/Green, PHP/frontend/API гейты, полный CI, обязательное ревью завершены |
| G02 | Merge/deploy/smoke false flag и постоянные отчёты |

- [ ] B01–B03: серверные данные и границы приняты.
- [ ] U01–U04: UI-правила, сеть и кэш приняты.
- [ ] E01–E05: browser-путь и восстановление приняты.
- [ ] G01–G02: проверки, ревью и доставка завершены.
