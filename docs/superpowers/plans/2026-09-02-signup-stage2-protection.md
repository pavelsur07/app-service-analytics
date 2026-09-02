# Signup Stage 2 Protection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Защитить публичный `POST /api/auth/sign-up` двумя распределёнными лимитами и серверной проверкой Yandex SmartCaptcha до Argon2 и любых записей.

**Architecture:** Контроллер после дешёвой валидации вызывает `RegistrationProtection`. Компонент расходует email- и IP-лимитеры, затем обращается к доменному порту `CaptchaVerifier`; инфраструктурный адаптер инкапсулирует HTTP-контракт Yandex. Все отказные исходы возвращаются до password hasher и registration action.

**Tech Stack:** PHP 8.4, Symfony 7.4 HttpClient и RateLimiter, Redis, PHPUnit 13, Monolog, Docker Compose, OpenAPI.

**Spec:** `docs/superpowers/specs/2026-09-02-signup-stage2-protection-design.md`

## Обязательные ограничения

- Не устанавливать новые Composer- или NPM-пакеты.
- Не добавлять frontend, OAuth, таблицы или миграции.
- Production: sliding window 5/email/hour и 30/IP/hour; test: 2/email/hour и 3/IP/hour.
- Всегда расходовать оба лимита до проверки их результата; при любом отказе CAPTCHA не вызывать.
- Передавать в Redis только HMAC-SHA-256 идентификаторы с `%kernel.secret%`, никогда email или IP.
- Не повторять запрос SmartCaptcha автоматически: токен одноразовый.
- Не логировать server key, CAPTCHA token, email, IP, тело или `message` Yandex.
- При отсутствии реального production server key остановить rollout; секрет не печатать.
- Каждый production-шаг начинается с красного теста и завершается зелёным тестом и небольшим коммитом.

## Карта файлов

Новые production-файлы:

- `api/src/Identity/Domain/CaptchaVerification.php`
- `api/src/Identity/Domain/CaptchaVerifier.php`
- `api/src/Identity/Domain/CaptchaUnavailableReason.php`
- `api/src/Identity/Domain/CaptchaUnavailable.php`
- `api/src/Identity/Infrastructure/AntiBot/YandexSmartCaptchaVerifier.php`
- `api/src/Identity/Ui/Security/RegistrationProtectionDecision.php`
- `api/src/Identity/Ui/Security/RegistrationProtectionResult.php`
- `api/src/Identity/Ui/Security/RegistrationProtection.php`

Новые test/config-файлы:

- `api/tests/Unit/Identity/Infrastructure/AntiBot/YandexSmartCaptchaVerifierTest.php`
- `api/tests/Unit/Identity/Ui/Security/RegistrationProtectionTest.php`
- `api/tests/Support/Fake/FakeCaptchaVerifier.php`
- `api/tests/Support/Probe/PasswordHasherProbe.php`
- `api/config/services_test.yaml`
- `bin/tests/smartcaptcha-production-config.test.sh`

Изменяемые файлы:

- `api/config/packages/framework.yaml`
- `api/config/packages/rate_limiter.yaml`
- `api/config/services.yaml`
- `api/.env`
- `api/.env.test`
- `api/src/Identity/Ui/Request/SelfRegistrationRequest.php`
- `api/src/Identity/Ui/Controller/SelfRegistrationController.php`
- `api/tests/Functional/Identity/SelfRegistrationControllerTest.php`
- `docker-compose.prod.yml`
- `.github/workflows/ci.yml`
- `docs/operations-checklist.md`
- `packages/api-schema/openapi.json`
- `packages/api-schema/src/schema.d.ts`

## Подготовка выполнения

- [ ] Создать feature-ветку от коммита с утверждёнными spec и plan:

```bash
git switch -c feat/signup-stage2-protection
```

- [ ] Убедиться, что рабочее дерево чистое и миграций в плане нет:

```bash
git status --short --branch
git diff --name-only origin/master...HEAD
```

### Task 1: Доменный порт и адаптер Yandex SmartCaptcha

**Files:**

- Create: `api/src/Identity/Domain/CaptchaVerification.php`
- Create: `api/src/Identity/Domain/CaptchaVerifier.php`
- Create: `api/src/Identity/Domain/CaptchaUnavailableReason.php`
- Create: `api/src/Identity/Domain/CaptchaUnavailable.php`
- Create: `api/src/Identity/Infrastructure/AntiBot/YandexSmartCaptchaVerifier.php`
- Create: `api/tests/Unit/Identity/Infrastructure/AntiBot/YandexSmartCaptchaVerifierTest.php`
- Modify: `api/config/packages/framework.yaml`
- Modify: `api/config/services.yaml`
- Modify: `api/.env`
- Modify: `api/.env.test`

- [ ] Написать unit-тесты адаптера. Через `MockHttpClient` зафиксировать один `POST /validate`, form-поля `secret`, `token`, `ip`, а также отсутствие повторов.

- [ ] Добавить случаи `status=ok` и `status=failed` с пустым и непустым `message`:

```php
self::assertSame(CaptchaVerification::Passed, $verifier->verify('captcha-token', '203.0.113.8'));
self::assertSame(CaptchaVerification::Rejected, $verifier->verify('captcha-token', '203.0.113.8'));
```

- [ ] Добавить красные случаи transport exception, timeout, HTTP не 2xx, invalid JSON, отсутствующий и неизвестный `status`. Проверить соответствующий `CaptchaUnavailableReason` и необязательный HTTP status.

- [ ] Проверить логирование: unavailable даёт ровно один `warning` только со стабильными полями `reason` и при наличии `http_status`; rejected не логируется. Выполнить негативные assertions для server key, token, IP, тела и `message`.

- [ ] Запустить тест и подтвердить ожидаемое падение из-за отсутствующих типов:

```bash
docker compose exec php-cli vendor/bin/phpunit tests/Unit/Identity/Infrastructure/AntiBot/YandexSmartCaptchaVerifierTest.php
```

- [ ] Создать enum `CaptchaVerification` со значениями `Passed` и `Rejected`, а также интерфейс:

```php
interface CaptchaVerifier
{
    public function verify(string $token, string $clientIp): CaptchaVerification;
}
```

- [ ] Создать enum причин `Transport`, `HttpStatus`, `InvalidJson`, `UnexpectedStatus` и final exception `CaptchaUnavailable`, который хранит причину и `?int $httpStatus`, но не включает внешнее тело или секреты в сообщение/previous exception.

- [ ] Реализовать `YandexSmartCaptchaVerifier`: один form POST, `getStatusCode()` до разбора, `toArray(false)`, строгое ветвление только по `status`. Любой неописанный исход преобразовать в sanitized `CaptchaUnavailable` и один warning.

- [ ] В `framework.yaml` объявить scoped client `smartcaptcha.client` с base URI `https://smartcaptcha.cloud.yandex.ru`, `timeout: 1.0`, `max_duration: 2.0` и без retry.

- [ ] В `services.yaml` связать `CaptchaVerifier` с адаптером, передать scoped client и добавить bind `string $smartCaptchaServerKey: '%env(SMARTCAPTCHA_SERVER_KEY)%'`. Использовать `%kernel.secret%` только позже для HMAC.

- [ ] Добавить явно фиктивное несекретное `SMARTCAPTCHA_SERVER_KEY` в `api/.env` и `api/.env.test`.

- [ ] Запустить focused unit test и статические проверки затронутого слоя:

```bash
docker compose exec php-cli vendor/bin/phpunit tests/Unit/Identity/Infrastructure/AntiBot/YandexSmartCaptchaVerifierTest.php
make lint
make stan
make deptrac
```

- [ ] Зафиксировать Task 1:

```bash
git add api/src/Identity/Domain/CaptchaVerification.php api/src/Identity/Domain/CaptchaVerifier.php api/src/Identity/Domain/CaptchaUnavailableReason.php api/src/Identity/Domain/CaptchaUnavailable.php api/src/Identity/Infrastructure/AntiBot/YandexSmartCaptchaVerifier.php api/tests/Unit/Identity/Infrastructure/AntiBot/YandexSmartCaptchaVerifierTest.php api/config/packages/framework.yaml api/config/services.yaml api/.env api/.env.test
git commit -m "Добавляет адаптер Yandex SmartCaptcha"
```

### Task 2: Оркестратор защиты и распределённые лимитеры

**Files:**

- Create: `api/src/Identity/Ui/Security/RegistrationProtectionDecision.php`
- Create: `api/src/Identity/Ui/Security/RegistrationProtectionResult.php`
- Create: `api/src/Identity/Ui/Security/RegistrationProtection.php`
- Create: `api/tests/Unit/Identity/Ui/Security/RegistrationProtectionTest.php`
- Create: `api/tests/Support/Fake/FakeCaptchaVerifier.php`
- Modify: `api/config/packages/rate_limiter.yaml`
- Modify: `api/config/services.yaml`

- [ ] Написать unit-тесты `RegistrationProtection` с fake limiter factories и fake CAPTCHA. Покрыть allowed, rejected и unavailable.

- [ ] Доказать порядок и расход обоих лимитов: даже если первый отклонён, `consume()` второго вызван; если любой отклонён, verifier не вызван.

- [ ] Доказать ключи без PII. Ожидаемые значения вычислять независимо:

```php
$emailKey = hash_hmac('sha256', 'email:owner@example.com', 'test-kernel-secret');
$ipKey = hash_hmac('sha256', 'ip:203.0.113.8', 'test-kernel-secret');
```

- [ ] Проверить, что при двух отказах возвращается более поздний `retryAfter`, а отсутствие IP возвращает unavailable и не вызывает limiter/verifier.

- [ ] Проверить sanitized warning для отсутствующего IP. Для `CaptchaUnavailable` проверить отсутствие второго warning: внешний сбой уже ровно один раз записал адаптер.

- [ ] Запустить новый тест и подтвердить красное состояние:

```bash
docker compose exec php-cli vendor/bin/phpunit tests/Unit/Identity/Ui/Security/RegistrationProtectionTest.php
```

- [ ] Создать `RegistrationProtectionDecision` со значениями `Allowed`, `RateLimited`, `CaptchaRejected`, `Unavailable` и result object с nullable `DateTimeImmutable $retryAfter`.

- [ ] Реализовать метод:

```php
public function check(string $normalizedEmail, ?string $clientIp, string $captchaToken): RegistrationProtectionResult
```

- [ ] Инжектировать две именованные `RateLimiterFactoryInterface`, `CaptchaVerifier`, `LoggerInterface` и `$kernelSecret`. Сначала проверить IP, затем создать оба HMAC-ключа, вызвать оба `consume()`, выбрать максимальный retry, и только после допуска вызвать verifier.

- [ ] Не позволять исключению адаптера выйти из компонента: преобразовать `CaptchaUnavailable` в `Unavailable` без повторного логирования.

- [ ] Добавить reusable `FakeCaptchaVerifier`, который настраивает исход, считает вызовы и запоминает полученные token/IP для unit и functional tests.

- [ ] Добавить в `rate_limiter.yaml`:

```yaml
framework:
    rate_limiter:
        registration_email:
            policy: sliding_window
            limit: 5
            interval: '1 hour'
        registration_ip:
            policy: sliding_window
            limit: 30
            interval: '1 hour'

when@test:
    framework:
        rate_limiter:
            registration_email:
                limit: 2
            registration_ip:
                limit: 3
```

- [ ] В `services.yaml` явно передать `$registrationEmailLimiter`, `$registrationIpLimiter` и `string $kernelSecret: '%kernel.secret%'`; не полагаться на неоднозначный autowiring двух фабрик.

- [ ] Запустить тесты и проверки:

```bash
docker compose exec php-cli vendor/bin/phpunit tests/Unit/Identity/Ui/Security/RegistrationProtectionTest.php
docker compose exec php-cli vendor/bin/phpunit tests/Unit/Identity/Infrastructure/AntiBot/YandexSmartCaptchaVerifierTest.php
make lint
make stan
make deptrac
```

- [ ] Зафиксировать Task 2:

```bash
git add api/src/Identity/Ui/Security api/tests/Unit/Identity/Ui/Security/RegistrationProtectionTest.php api/tests/Support/Fake/FakeCaptchaVerifier.php api/config/packages/rate_limiter.yaml api/config/services.yaml
git commit -m "Добавляет лимиты самостоятельной регистрации"
```

### Task 3: HTTP-контракт и доказательство раннего отказа

**Files:**

- Create: `api/tests/Support/Probe/PasswordHasherProbe.php`
- Create: `api/config/services_test.yaml`
- Modify: `api/src/Identity/Ui/Request/SelfRegistrationRequest.php`
- Modify: `api/src/Identity/Ui/Controller/SelfRegistrationController.php`
- Modify: `api/tests/Functional/Identity/SelfRegistrationControllerTest.php`

- [ ] Сначала расширить functional tests. Во все существующие валидные payload добавить фиктивный `captchaToken`, чтобы прежний `202`-сценарий оставался зелёным при разрешающем fake.

- [ ] Добавить data provider для отсутствующего, нестрокового, пустого и длиннее 4096 символов token. Ожидать `422` с `code=captcha_invalid`, ноль вызовов limiter/verifier/password hasher и отсутствие новых записей, audit и писем.

- [ ] Добавить тест `status=failed`: ожидать `422 captcha_invalid`, оба лимита израсходованы, hasher и registration action не дали побочных эффектов.

- [ ] Добавить тест unavailable: ожидать `503 captcha_unavailable`, `Retry-After: 30` и нулевые бизнес-побочные эффекты. Единственный sanitized warning внешнего сбоя уже проверяет unit-тест реального адаптера.

- [ ] Добавить rate-limit тесты на реальном Redis:

  - один email в разных регистрах и с разных IP отклоняется третьим test-запросом;
  - разные email с одного IP отклоняются четвёртым test-запросом;
  - чужая пара email/IP не наследует счётчик;
  - при отказе fake CAPTCHA не вызывается;
  - `Retry-After` присутствует и не меньше нуля.

- [ ] Очищать `cache.rate_limiter` Redis pool до и после каждого rate-limit сценария в `try/finally`: PostgreSQL rollback не откатывает Redis.

- [ ] Добавить два proxy-теста фактического поведения Symfony. При приватном доверенном `REMOTE_ADDR` fake получает адрес из `X-Forwarded-For`; при публичном недоверенном `REMOTE_ADDR` fake получает `REMOTE_ADDR`, а подложный заголовок игнорируется.

- [ ] Создать `PasswordHasherProbe`, полностью реализующий три метода `UserPasswordHasherInterface`, делегирующий настоящему test hasher и считающий `hashPassword()`.

- [ ] В `services_test.yaml` заменить `CaptchaVerifier` на resettable shared `FakeCaptchaVerifier`, а `UserPasswordHasherInterface` — на probe с явным inner service. Обеспечить независимость тестов через reset в `setUp()`.

- [ ] Запустить functional-файл и подтвердить красное состояние на первом новом ожидании:

```bash
docker compose exec php-cli vendor/bin/phpunit tests/Functional/Identity/SelfRegistrationControllerTest.php
```

- [ ] В `SelfRegistrationRequest` добавить `MAX_CAPTCHA_TOKEN_LENGTH = 4096` и readonly `captchaToken`. Проверять token после существующих дешёвых полей и не trim/normalize его перед передачей провайдеру.

- [ ] В контроллер инжектировать `RegistrationProtection`, вызвать его с `User::normalizeEmail($payload->email)`, `$request->getClientIp()` и исходным token строго до `hashPassword()`.

- [ ] Отобразить результаты защиты:

  - `CaptchaRejected` → `422`, `code=captcha_invalid`;
  - `RateLimited` → `429`, `code=registration_rate_limited`, `Retry-After` как секунды до absolute retry time;
  - `Unavailable` → `503`, `code=captcha_unavailable`, `Retry-After: 30`;
  - `Allowed` → существующий hash/action/`202`.

- [ ] Не возвращать диагностические данные. Использовать стабильные русские клиентские сообщения и существующий формат `ValidationErrorResponse`.

- [ ] Дополнить OA-атрибут: `captchaToken` required, string, `maxLength: 4096`; документировать ответы `429` и `503` и заголовок `Retry-After`.

- [ ] Запустить focused functional и весь Identity functional набор:

```bash
docker compose exec php-cli vendor/bin/phpunit tests/Functional/Identity/SelfRegistrationControllerTest.php
docker compose exec php-cli vendor/bin/phpunit tests/Functional/Identity
make lint
make stan
make deptrac
```

- [ ] Проверить в тестах не только HTTP, но и ноль строк companies/users/memberships/tokens/audit и ноль отправленных писем во всех отказных исходах.

- [ ] Зафиксировать Task 3:

```bash
git add api/src/Identity/Ui/Request/SelfRegistrationRequest.php api/src/Identity/Ui/Controller/SelfRegistrationController.php api/tests/Functional/Identity/SelfRegistrationControllerTest.php api/tests/Support/Probe/PasswordHasherProbe.php api/config/services_test.yaml
git commit -m "Защищает endpoint самостоятельной регистрации"
```

### Task 4: Production secret, CI guard, контракт и эксплуатация

**Files:**

- Create: `bin/tests/smartcaptcha-production-config.test.sh`
- Modify: `docker-compose.prod.yml`
- Modify: `.github/workflows/ci.yml`
- Modify: `docs/operations-checklist.md`
- Modify: `packages/api-schema/openapi.json`
- Modify: `packages/api-schema/src/schema.d.ts`

- [ ] Написать shell regression test в стиле существующих `bin/tests/*.test.sh`. Он должен собрать минимальный временный env всех обязательных production-переменных и доказать:

  - `docker compose -f docker-compose.prod.yml config` падает без `SMARTCAPTCHA_SERVER_KEY`;
  - та же команда проходит с явно фиктивным test-значением;
  - ключ присутствует только в окружении `api`, но не worker-сервисов;
  - значение тестового ключа не печатается самим test script при успехе.

- [ ] Запустить новый guard и подтвердить, что до правки Compose негативная проверка падает:

```bash
bin/tests/smartcaptcha-production-config.test.sh
```

- [ ] Изменить только service `api`, сохранив общий anchor у workers:

```yaml
api:
  environment:
    <<: *api-env
    SMARTCAPTCHA_SERVER_KEY: ${SMARTCAPTCHA_SERVER_KEY:?}
```

- [ ] Не добавлять server key в `x-api-env`: worker-процессам секрет не нужен.

- [ ] В CI заменить одиночный вызов config-test на fail-fast цикл по всем `bin/tests/*.test.sh`:

```bash
for test_script in bin/tests/*.test.sh; do
  "$test_script"
done
```

- [ ] Обновить `docs/operations-checklist.md`: наличие `SMARTCAPTCHA_SERVER_KEY`, fail-closed `503`, sanitized warning, `429`, порядок rollback и отложенный до Stage 3 live success-smoke через виджет.

- [ ] Сгенерировать OpenAPI и TypeScript-типы только штатными целями:

```bash
make api-doc-export
make api-types
make api-types-check
```

- [ ] Проверить, что generated contract содержит required `captchaToken`, `422`, `429`, `503`, а unrelated endpoints не изменились без причины:

```bash
git diff -- packages/api-schema/openapi.json packages/api-schema/src/schema.d.ts
```

- [ ] Запустить все shell guards:

```bash
for test_script in bin/tests/*.test.sh; do "$test_script"; done
```

- [ ] Зафиксировать Task 4:

```bash
git add docker-compose.prod.yml .github/workflows/ci.yml docs/operations-checklist.md bin/tests/smartcaptcha-production-config.test.sh packages/api-schema/openapi.json packages/api-schema/src/schema.d.ts
git commit -m "Требует SmartCaptcha secret в production"
```

### Task 5: Полная верификация и внешнее ревью

**Files:**

- Review: все файлы из Tasks 1–4
- Review artifacts: `var/review/codex.md`, `var/review/codex-defects.md` (не коммитить)

- [ ] Запустить автоформатирование, проверить только ожидаемые изменения и затем весь backend quality gate:

```bash
make lint-fix
git diff --check
make lint
make stan
make deptrac
make structure-check
```

- [ ] Запустить все backend suites на подготовленной test DB:

```bash
make db-wait
make db-test-create
make api-migrate-test
make test-unit
make test-int
make test-func
```

- [ ] Повторить contract/config guards:

```bash
make api-types-check
for test_script in bin/tests/*.test.sh; do "$test_script"; done
```

- [ ] Проверить отсутствие незапланированных зависимостей и миграций:

```bash
git diff origin/master...HEAD -- api/composer.json api/composer.lock package.json '*/package.json' '*/package-lock.json' api/migrations
```

- [ ] Проверить секреты и PII в diff. Поиск должен вернуть только имена переменных, тестовые маркеры и assertions, но не реальный key/token/email/IP:

```bash
git diff origin/master...HEAD | rg -n 'SMARTCAPTCHA|captcha-token|203\.0\.113|owner@example'
```

- [ ] Использовать `superpowers:requesting-code-review`: подготовить пакет и запустить обе независимые роли репозитория.

```bash
make review TASK="Stage 2 signup protection: SmartCaptcha, distributed email/IP rate limits, fail-closed HTTP contract"
```

- [ ] Прочитать оба отчёта полностью. Для каждого замечания применить `superpowers:receiving-code-review`: воспроизвести факт, отделить реальный дефект от неверного предположения, исправить подтверждённые проблемы через новый красный тест.

```bash
sed -n '1,260p' var/review/codex.md
sed -n '1,260p' var/review/codex-defects.md
```

- [ ] После исправлений снова выполнить focused test, затем весь gate из первых трёх шагов Task 5. Не принимать старый зелёный результат после изменения кода.

- [ ] Убедиться, что рабочее дерево содержит только ожидаемый Stage 2 diff, и зафиксировать review-fixes отдельным коммитом только если были изменения:

```bash
git status --short
git diff --check
git add api .github/workflows/ci.yml bin/tests/smartcaptcha-production-config.test.sh docker-compose.prod.yml docs/operations-checklist.md packages/api-schema
git diff --cached --stat
git commit -m "Устраняет замечания ревью защиты регистрации"
```

### Task 6: Production preflight, PR, merge и rollout

**Files:**

- Verify only: production `/opt/conwix/.env`
- Verify only: GitHub PR and Actions state
- Verify only: production services after automatic deploy

- [ ] В доверенной операторской сессии задать только SSH target и проверить наличие непустого server key, не выводя ни файл, ни значение:

```bash
: "${PRODUCTION_SSH_TARGET:?задайте SSH target production-сервера}"
ssh "$PRODUCTION_SSH_TARGET" 'set -eu
  test -f /opt/conwix/.env
  awk '\''BEGIN { found=0 }
       /^SMARTCAPTCHA_SERVER_KEY=.+$/ { found=1 }
       END { exit(found ? 0 : 1) }'\'' /opt/conwix/.env'
```

- [ ] Если preflight завершился ненулевым кодом, остановиться до push/merge и попросить владельца безопасно добавить реальный `SMARTCAPTCHA_SERVER_KEY` в `/opt/conwix/.env`. Не подставлять dummy и не просить прислать значение в чат.

- [ ] Выполнить финальную проверку ветки по `superpowers:verification-before-completion` и записать свежие результаты в PR summary:

```bash
git status --short --branch
git log --oneline origin/master..HEAD
git diff --check origin/master...HEAD
make ci-local
```

- [ ] Отправить feature-ветку и создать PR с целью, границами, threat model, тестами, production preflight и пометкой, что live success-smoke отложен до Stage 3:

```bash
git push -u origin feat/signup-stage2-protection
gh pr create --base master --head feat/signup-stage2-protection \
  --title "Защищает самостоятельную регистрацию" \
  --body "Stage 2: Yandex SmartCaptcha и распределённые email/IP rate limits до Argon2. Новых пакетов и миграций нет. Проверено: make ci-local, config guards, два внешних review; наличие production server key подтверждено без раскрытия. Live status=ok smoke выполняется в Stage 3 вместе с виджетом."
```

- [ ] Дождаться всех обязательных CI checks и проверить review decision. Не merge при pending/failure:

```bash
gh pr checks --watch --fail-fast
gh pr view --json number,url,mergeStateStatus,reviewDecision,statusCheckRollup
```

- [ ] После зелёного CI и принятого внешнего ревью смержить PR штатным способом репозитория и удалить удалённую feature-ветку:

```bash
gh pr merge --merge --delete-branch
```

- [ ] Дождаться завершения workflow merge-коммита, включая автоматическую production deployment job:

```bash
merge_sha=$(gh pr view --json mergeCommit --jq '.mergeCommit.oid')
run_id=$(gh run list --commit "$merge_sha" --workflow ci.yml --limit 1 --json databaseId --jq '.[0].databaseId')
test -n "$run_id"
gh run watch "$run_id" --exit-status
```

- [ ] Проверить публично, что production отдаёт новый тег, и что запрос без CAPTCHA завершается до регистрации. Использовать уникальный адрес в зарезервированном домене `.invalid`; никакой реальный номер телефона не нужен Stage 2:

```bash
version=$(curl -fsS https://app.conwix.com/api/seller/ping | sed -E 's/.*"version":"([^"]+)".*/\1/')
short_sha=$(printf '%s' "$merge_sha" | cut -c1-7)
case "$version" in
  *-"$short_sha") ;;
  *) echo "production version $version не соответствует merge $short_sha" >&2; exit 1 ;;
esac

smoke_email="stage2-smoke-$(date -u +%Y%m%d%H%M%S)@example.invalid"
response=$(curl -sS https://app.conwix.com/api/auth/sign-up \
  -H 'Content-Type: application/json' \
  --data "{\"email\":\"$smoke_email\",\"password\":\"Stage2SmokeOnly-2026\",\"companyName\":\"Stage 2 smoke\",\"legalConsent\":true}" \
  -w '\n%{http_code}')
status=$(printf '%s\n' "$response" | tail -n 1)
body=$(printf '%s\n' "$response" | sed '$d')
test "$status" = 422
printf '%s' "$body" | grep -q '"code":"captcha_invalid"'
```

- [ ] Проверить production с сервера без печати секретов: все сервисы healthy, публичный image tag уже совпал с merge workflow, migrations up to date.

```bash
ssh "$PRODUCTION_SSH_TARGET" 'set -eu
  cd /opt/conwix
  docker compose -f docker-compose.prod.yml ps --format "{{.Service}} {{.Status}}"
  ! docker compose -f docker-compose.prod.yml ps --format "{{.Status}}" | grep -qE "unhealthy|Restarting"
  docker compose -f docker-compose.prod.yml exec -T api php bin/console doctrine:migrations:up-to-date'
```

- [ ] Зафиксировать итог в задаче/PR: URL PR, merge SHA, workflow run, deployed tag, результат `422 captcha_invalid`, отсутствие миграции. Явно оставить live `status=ok` smoke открытым до Stage 3, где появится виджет и настоящий одноразовый token.

## Definition of Done

- [ ] Дешёвая DTO-валидация предшествует limiter, CAPTCHA и Argon2.
- [ ] Оба Redis-лимита расходуются и не раскрывают email/IP.
- [ ] Только SmartCaptcha `status=ok` допускает хэширование и регистрацию.
- [ ] `422`, `429` и `503` стабильны, документированы и не имеют бизнес-побочных эффектов.
- [ ] Test suite не обращается во внешнюю сеть и доказывает trusted-proxy поведение.
- [ ] Production Compose требует server key только для `api`.
- [ ] Полный локальный gate, внешнее ревью и CI зелёные.
- [ ] PR смержен, production deployment подтверждён; live success-smoke явно перенесён в Stage 3.
