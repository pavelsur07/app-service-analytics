# Этап 0. Исходное состояние и доказательства угроз

## Задача и результат

Подготовить воспроизводимый локальный стенд и доказательства для TDD, не
меняя поведение продукта. Зафиксировать матрицу контуров и проверок,
чтобы каждый следующий этап начинался с теста и точных критериев.

Основание: `security-remediation.md`, `securety_chek.md`, поручение владельца
начать поэтапное выполнение с подробными планами и TDD от 25.10.2026.
Поручение начать выполнение трактуется как принятие описанного в общем
плане отзыва токенов и CSRF-контракта; новая библиотека MFA пока не выбрана
и этим поручением не согласована.

## Исходное состояние

- База: `cd4fb15`, ветка `docs/security-remediation-plan`.
- Пользовательский `docs/plan/securety_chek.md` не отслеживается Git;
  не изменять и не включать в коммиты как собственную работу.
- Контейнеры проекта не запущены; PHP/Node-зависимости не установлены.
- Claude CLI отсутствует в PATH; обязательное ревью и merge пока недоступны.
  Это ограничение готовности поставки, не основание заменить Claude Codex-ревью.
- Разработка остаётся в существующей ветке задачи и checkout, чтобы
  контейнерные bind mounts и пользовательский отчёт оставались на месте.

## Последовательность

- [ ] Собрать dev-образы существующей целью `make build`, запустить
  локальные контейнеры, установить зафиксированные зависимости Make-целями.
- [ ] Мигрировать dev/test-базы, создать бакет и проверить исходные тесты
  Identity и frontend; при сбое сохранить точную причину.
- [ ] Составить матрицу изменяющих маршрутов и origin по конфигурации
  маршрутов, security, Nginx и Traefik; отделить session/Bearer/internal.
- [ ] Сначала написать regression-тест отзыва токена после сброса, увидеть
  ожидаемое падение (не ошибку окружения). Исправлять только в этапе 1.
- [ ] Перед этапом 2 сначала написать тест CSRF через сессию SuperAdmin;
  перед этапом 3 — тест раннего сообщения/неготового storage;
  перед этапом 4 — поведенческую проверку отказа audit при ошибке extension.
  Эти RED-проверки принадлежат планам соответствующих этапов, не дублируются здесь.
- [ ] Выполнить базовый аудит зависимостей и Go; сохранить результаты
  и ограничения без ложного объявления успешной проверки.
- [ ] Создать журнал команд и результатов `var/security-remediation/`;
  существенные результаты переносить в версионируемый отчёт этапа.

## Чек-лист готовности

- [ ] Среда подтверждена как локальная, без боевых подключений и операций.
- [ ] Проверки реально выполняются в контейнерах либо блокировка описана.
- [ ] Базовая версия, собственный diff и чужие файлы разграничены.
- [ ] Есть матрица маршрутов и origin, учтены login/logout/pre-auth.
- [ ] У каждой находки определена проверка поведения и этап устранения.
- [ ] Все недоступные проверки/ревью названы, ни одна не зачтена как успешная.

Этап 0 готов после выполнения этого списка; наличие RED-тестов является
доказательством угрозы, а не готовностью исправления. Планы этапов 1–5
публикуются до их реализации; каждый содержит задачу, результат, файлы,
RED→GREEN-проверки и отдельный чек-лист готовности.

## Матрица маршрутов и origin (снята с исходной версии)

| Контур | Методы | Маршрут |
|---|---|---|
| session admin | POST | `/api/admin/auth/login` |
| session admin | POST | `/api/admin/administrators` |
| session admin | POST | `/api/admin/companies/{id}/status` |
| session seller / pre-auth | POST | `/api/companies/{companyId}/extension-tokens` |
| session seller / pre-auth | DELETE | `/api/companies/{companyId}/extension-tokens/{id}` |
| session seller / pre-auth | POST | `/api/auth/password-reset/request` |
| session seller / pre-auth | POST | `/api/auth/password-reset/confirm` |
| session seller / pre-auth | POST | `/api/auth/email-verification/confirm` |
| session admin | POST | `/api/admin/companies` |
| session seller / pre-auth | POST | `/api/auth/email-verification/resend` |
| session seller / pre-auth | POST | `/api/auth/sign-up` |
| session seller / pre-auth | POST | `/api/auth/login` |
| session seller / pre-auth | POST | `/api/companies/{companyId}/connections` |
| session seller / pre-auth | PUT | `/api/companies/{companyId}/listing-costs/{costId}` |
| session seller / pre-auth | DELETE | `/api/companies/{companyId}/connections/{marketplaceAccountId}` |
| session seller / pre-auth | PUT | `/api/companies/{companyId}/connections/{marketplaceAccountId}/advertising-credentials` |
| session seller / pre-auth | PUT | `/api/companies/{companyId}/connections/{marketplaceAccountId}/credentials` |
| session seller / pre-auth | POST | `/api/companies/{companyId}/listing-costs` |
| session admin | POST | `/api/admin/links/{id}/status` |
| session admin | POST | `/api/admin/links` |
| session admin | POST | `/api/admin/links/{id}` |
| Bearer extension | POST | `/api/extension/companies/{companyId}/price-observations` |
| Bearer extension | POST | `/api/extension/companies/{companyId}/tracked-skus` |
| Bearer extension | POST | `/api/extension/companies/{companyId}/tracked-skus/{marketplaceSku}/stop` |
| session seller / pre-auth | PUT | `/api/companies/{companyId}/sales-plans/{planId}/days` |
| session seller / pre-auth | POST | `/api/companies/{companyId}/sales-plans` |
| session seller / pre-auth | DELETE | `/api/companies/{companyId}/sales-plans/{planId}/skus/{marketplaceSku}` |
| session seller / pre-auth | PUT | `/api/companies/{companyId}/sales-plans/{planId}/skus` |

Logout обрабатывается firewall: `POST /api/auth/logout` и
`POST /api/admin/auth/logout`; не терять их из-за отсутствия в debug:router.

Production origins: `https://app.conwix.com`, `https://admin.conwix.com`.
Dev: `http://app.conwix.localhost`, `http://admin.conwix.localhost` с фактическим
портом Traefik; контейнерный e2e: соответствующие `*.conwix.internal`.
Origin выбирается по контуру и точной конфигурации среды, не произвольному Host.

Базовые результаты: `make audit` — успешно (Composer/seller/admin/api-schema);
отдельный npm audit extension — 1 high; extension Vitest — 69/69.
RED этапа 1: два ожидаемых integration failures и HTTP 200 вместо 401.
Окружение подготовлено с CONTAINER_UID/GID=1001; первоначальная установка
с умолчанием 1000 завершилась отказом прав, исправлено настройкой запуска.
