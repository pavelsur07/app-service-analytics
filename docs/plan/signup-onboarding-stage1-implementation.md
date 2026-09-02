# Signup and Email Confirmation — Stage 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Дать продавцу самостоятельно и атомарно создать пару «компания + владелец», подтвердить адрес письмом, получить сессию и безопасно удалить брошенный неподтверждённый аккаунт ручной командой.

**Architecture:** Существующий `RegisterClientAccountAction` остаётся единственной операцией создания пары и получает отдельный self-registration entry point; административный и публичный HTTP-сценарии различаются только актором, согласием, письмом и внешним ответом. Подтверждение — отдельная append-only сущность с SHA-256 токеном и DBAL-переходом `consumed_at IS NULL`; штатный Symfony `UserCheckerInterface` запрещает вход неподтверждённым пользователям на каждой аутентификации. Уборка — явная ручная операция: exclusive advisory-lock отдельным statement, затем один консервативный modifying SQL, который не удаляет компанию при наличии подтверждённого пользователя, живого confirmation token, подключения или company-scoped данных.

**Tech Stack:** PHP 8.4, Symfony 7.4 Security/Mailer/Messenger/Console, Doctrine ORM 3.6 + DBAL 4.4, PostgreSQL 16, PHPUnit 13, OpenAPI/Nelmio. Новые Composer-пакеты не устанавливаются. Будущий OAuth Ozon использует уже установленные `symfony/http-client`, `symfony/lock`, Symfony Session и существующее шифрование credentials; `league/oauth2-client` и OAuth-bundle не добавляются.

**Spec:** `docs/task/task-signup-onboarding-ozon-oauth.md` — только Stage 1; новый ADR получает фактически свободный номер ADR-021.

## Global Constraints

- Один пакет внешнего ревью реализует только Stage 1; Stage 2–6 не входят.
- ADR-021 создаётся со статусом `Proposed` до кода и остаётся `Proposed`, пока не подтверждены все относящиеся к нему этапы 1–4.
- `Company.status` остаётся только `active | blocked`; подтверждение хранится у `User`.
- Существующие и созданные администратором пользователи после миграции остаются способными войти; неподтверждёнными создаются только self-signup пользователи.
- Компания, владелец, membership, confirmation token и `company.registered` фиксируются одной транзакцией.
- Конфликт email определяется уникальным индексом `uq_user_email`; предварительного `findByEmail()` в регистрации нет.
- Наружный ответ self-signup одинаков для свободного и занятого email; письма различаются.
- Открытый confirmation token нигде не хранится и не логируется; в PostgreSQL лежит только SHA-256.
- Срок confirmation token — ровно 24 часа; срок уборки — ровно 30 дней.
- Согласие обязательно только для self-signup; сервер сохраняет собственную текущую версию документов, а не доверяет версии из запроса.
- Письма отправляются через Symfony Mailer синхронно: открытый confirmation
  token не сериализуется в doctrine-очередь и не попадает в PostgreSQL.
- Проверка email выполняется через Symfony user checker на firewall `api` и `extension`, не в login-контроллере.
- Уборка запускается только командой человека; расписание и Messenger для неё не добавляются.
- Resend берёт shared advisory-lock до user lookup; cleanup берёт exclusive
  lock до eligibility snapshot, а свежий token защищает аккаунт до expiry.
- Миграция задачи одна, с рабочим `down()`; `make db-rebuild-check` обязателен.
- Работать в изолированном worktree: текущее дерево содержит несвязанные изменения процента выкупа, которые нельзя включать в пакет Stage 1.

---

### Task 1: ADR-021 и модель подтверждения

**Files:**
- Create: `docs/adr/0021-self-signup-email-confirmation-onboarding.md`
- Modify: `docs/adr/README.md`
- Create: `api/src/Identity/Domain/ValueObject/EmailVerificationSecret.php`
- Create: `api/src/Identity/Domain/EmailVerificationToken.php`
- Modify: `api/src/Identity/Domain/User.php`
- Create: `api/migrations/Version20260902120000.php`
- Create: `api/tests/Unit/Identity/Domain/EmailVerificationSecretTest.php`
- Modify: `api/tests/Support/Builder/UserBuilder.php`
- Create: `api/tests/Support/Builder/EmailVerificationTokenBuilder.php`
- Test: `api/tests/Integration/Identity/EmailVerificationSchemaTest.php`

**Interfaces:**
- Produces: `EmailVerificationSecret::generate(): self`, `EmailVerificationSecret::fromPlainText(string): self`, `plainText(): string`, `hash(): string`.
- Produces: `EmailVerificationToken::issue(Uuid $userId, string $tokenHash, DateTimeImmutable $issuedAt): self`, with expiry `issuedAt + 24 hours`.
- Produces: `User::selfRegister(string $email, string $passwordHash, DateTimeImmutable $consentedAt, string $legalDocumentsVersion): self`.
- Preserves: `User::register(string $email, string $passwordHash): self` for trusted/admin/test setup, with `emailConfirmedAt = createdAt`.

- [x] **Step 1: Create ADR-021 as Proposed and register it before code**

Copy the ADR-021 draft from the spec into `docs/adr/0021-self-signup-email-confirmation-onboarding.md`, then make only these semantic edits:

```markdown
## ADR-021: Самостоятельная регистрация, защита от роботов и минимальный онбординг

**Дата:** 2026-09-02
**Статус:** Proposed
```

Add registry row after ADR-020:

```markdown
| 021 | Самостоятельная регистрация, защита от роботов и минимальный онбординг | Proposed |
```

Add a registry note that ADR-021 partially supersedes ADR-007 only after acceptance; while Proposed, it records the staged implementation and does not yet alter Accepted decisions.

- [x] **Step 2: Write failing secret and schema tests**

In `EmailVerificationSecretTest`, assert 100 generated secrets are non-empty, pairwise different, and expose a 64-character lowercase SHA-256 hash that equals `hash('sha256', $secret->plainText())`.

In `EmailVerificationSchemaTest`, persist a self-registered user and token, then assert:

```php
self::assertNull($user->emailConfirmedAt());
self::assertSame('2026-09-02', $user->legalDocumentsVersion());
self::assertSame($consentedAt, $user->legalConsentAt());
self::assertSame($issuedAt->modify('+24 hours'), $token->expiresAt());
self::assertNull($token->consumedAt());
```

Also execute raw SQL twice with the same `token_hash` and assert the second insert raises `UniqueConstraintViolationException`.

- [x] **Step 3: Run the focused tests and confirm RED**

Run:

```bash
docker compose exec php-cli php bin/phpunit tests/Unit/Identity/Domain/EmailVerificationSecretTest.php
docker compose exec php-cli php bin/phpunit tests/Integration/Identity/EmailVerificationSchemaTest.php
```

Expected: failure because the secret, token entity, user factories and columns do not exist.

- [x] **Step 4: Implement the value object, entities and migration**

`EmailVerificationSecret` must generate `bin2hex(random_bytes(32))`; it must never implement `__toString()` or serialization that exposes the plain text.

Add nullable columns to `User`:

```php
#[ORM\Column(nullable: true)]
private ?\DateTimeImmutable $emailConfirmedAt;

#[ORM\Column(nullable: true)]
private readonly ?\DateTimeImmutable $legalConsentAt;

#[ORM\Column(length: 32, nullable: true)]
private readonly ?string $legalDocumentsVersion;
```

Use one captured `$createdAt` in each factory. `register()` sets `emailConfirmedAt` to that timestamp and consent fields to `null`; `selfRegister()` sets `emailConfirmedAt` to `null` and requires non-empty document version.

Map `EmailVerificationToken` to `email_verification_token` with:

```text
id uuid primary key
user_id uuid not null
token_hash char(64) unique not null
issued_at timestamp not null
expires_at timestamp not null
consumed_at timestamp null
```

Add `idx_email_verification_token_user_id` and `uq_email_verification_token_hash`. Do not add Doctrine associations. The migration must add the three user columns, backfill `email_confirmed_at = created_at` for all existing rows, then create the token table. `down()` drops the token table and the three user columns.

- [x] **Step 5: Update immutable builders**

`UserBuilder` defaults to confirmed and adds:

```php
public function unconfirmed(DateTimeImmutable $consentedAt = new DateTimeImmutable('2026-09-02T10:00:00+00:00'), string $documentsVersion = '2026-09-02'): self;
```

Because PHP does not allow `new` as a portable default in every supported context, implement the public method with nullable input and create the default inside the body. `build()` selects `User::selfRegister()` only when the builder was marked unconfirmed.

`EmailVerificationTokenBuilder` is immutable, accepts an explicit user and token hash, and persists through the repository introduced in Task 2; until then its `build()` is usable by schema tests through `EntityManagerInterface`.

- [x] **Step 6: Apply the migration and run focused tests GREEN**

Run:

```bash
make api-migrate
make api-migrate-test
docker compose exec php-cli php bin/phpunit tests/Unit/Identity/Domain/EmailVerificationSecretTest.php
docker compose exec php-cli php bin/phpunit tests/Integration/Identity/EmailVerificationSchemaTest.php
docker compose exec php-cli php bin/console doctrine:schema:validate
```

Expected: all pass; Doctrine mapping and database schema agree.

- [x] **Step 7: Commit Task 1**

```bash
git add docs/adr/0021-self-signup-email-confirmation-onboarding.md docs/adr/README.md api/src/Identity/Domain api/migrations api/tests/Unit/Identity api/tests/Integration/Identity/EmailVerificationSchemaTest.php api/tests/Support/Builder
git commit -m "Добавляет модель подтверждения email для регистрации"
```

### Task 2: Единая атомарная регистрация и неразличимый публичный ответ

**Files:**
- Modify: `api/src/Identity/Application/RegisterClientAccountAction.php`
- Create: `api/src/Identity/Application/SelfRegistrationResult.php`
- Create: `api/src/Identity/Domain/RegistrationEmailSender.php`
- Create: `api/src/Identity/Domain/EmailVerificationTokenRepository.php`
- Modify: `api/src/Identity/Domain/CompanyRepository.php`
- Modify: `api/src/Identity/Infrastructure/Repository/DoctrineCompanyRepository.php`
- Create: `api/src/Identity/Infrastructure/Repository/DoctrineEmailVerificationTokenRepository.php`
- Create: `api/src/Identity/Infrastructure/Notification/MailRegistrationEmailSender.php`
- Create: `api/src/Identity/Ui/Request/SelfRegistrationRequest.php`
- Create: `api/src/Identity/Ui/Response/SelfRegistrationResponse.php`
- Create: `api/src/Identity/Ui/Controller/SelfRegistrationController.php`
- Modify: `api/src/Identity/Domain/AuditAction.php`
- Modify: `api/config/packages/security.yaml`
- Modify: `api/config/services.yaml`
- Modify: `api/.env`
- Test: `api/tests/Functional/Identity/SelfRegistrationControllerTest.php`
- Modify: `api/tests/Functional/Identity/ClientAccountsControllerTest.php`

**Interfaces:**
- Produces: `RegisterClientAccountAction::selfRegister(string $companyName, string $ownerEmail, string $passwordHash, DateTimeImmutable $consentedAt, string $documentsVersion): SelfRegistrationResult`.
- Produces: `SelfRegistrationResult(bool $created)`; no company/user/token data is exposed to the controller.
- Produces: `RegistrationEmailSender::sendConfirmation(string $email, EmailVerificationSecret $secret): void` and `sendAlreadyRegistered(string $email): void`.
- Changes: `CompanyRepository::registerWithOwner(Company $company, User $owner, CompanyMember $membership, AuditRecord $trail, ?EmailVerificationToken $verificationToken = null): bool`; `true` means the whole aggregate appeared, `false` means the unique email rejected it and the transaction left no rows.

- [x] **Step 1: Write failing functional tests for both email branches**

Test `POST /api/auth/sign-up` with:

```json
{
  "email": "owner@example.com",
  "password": "correct-horse-battery-staple",
  "companyName": "Ромашка ООО",
  "legalConsent": true
}
```

Assert status `202` and exact body for both a free and pre-existing email:

```json
{"message":"Если адрес указан верно, письмо с дальнейшими инструкциями уже отправлено."}
```

For the free email, assert one company, one unconfirmed user, one owner membership, one verification token, consent timestamp/version, and one `company.registered` audit row with `actor_user_id = owner.id` and `actor_admin_id IS NULL`.

For the taken email, assert no additional company/membership/token/audit rows. Compare status and complete JSON body between the free/taken calls.

Use Symfony Mailer's test event log to assert that the free branch sent a
confirmation email and the taken branch sent an already-registered reminder.
Assert that neither message was queued and the Messenger test transport stayed
empty; never assert against a live SMTP server.

- [x] **Step 2: Write failing validation and admin-regression tests**

Add tests that `legalConsent: false`, missing consent, invalid email, blank company and password shorter than 12 chars return `422` without any database rows or queued email.

Keep the existing admin registration test and add:

```php
self::assertNotNull($owner->emailConfirmedAt(), 'admin-created owners remain usable without the public confirmation flow');
```

- [x] **Step 3: Run focused tests and confirm RED**

```bash
docker compose exec php-cli php bin/phpunit tests/Functional/Identity/SelfRegistrationControllerTest.php
docker compose exec php-cli php bin/phpunit tests/Functional/Identity/ClientAccountsControllerTest.php
```

Expected: signup route and new interfaces are absent.

- [x] **Step 4: Make the repository transaction return inserted/not-inserted**

Extend the existing transaction so its single flush includes optional `EmailVerificationToken`. Catch only `UniqueConstraintViolationException` caused by the unique user email, clear any closed EntityManager state if required by the current Doctrine version, and return `false`; rethrow unrelated database failures. Do not call `findByEmail()` before the insert.

Preserve the existing admin `__invoke()` API. It passes no token, uses `AuditRecord::recordByAdmin()` and returns conflict to its controller exactly as before, adapting the controller from exception branching to the boolean result only if the repository now contains the conflict.

- [x] **Step 5: Implement self-registration inside the shared action**

The self entry point must perform this order:

```php
$owner = User::selfRegister($ownerEmail, $passwordHash, $consentedAt, $documentsVersion);
$company = Company::register($companyName);
$membership = CompanyMember::create($company->id(), $owner->id(), CompanyMemberRole::Owner);
$secret = EmailVerificationSecret::generate();
$token = EmailVerificationToken::issue($owner->id(), $secret->hash(), $consentedAt);
$audit = AuditRecord::record($company->id(), $owner->id(), AuditAction::CompanyRegistered, $company->id(), null, $owner->email(), $consentedAt);
$created = $companies->registerWithOwner($company, $owner, $membership, $audit, $token);
```

After the transaction returns, call `sendConfirmation()` when `$created` is true, otherwise `sendAlreadyRegistered()`. Never include the secret in logs or in `SelfRegistrationResult` after the mail sender has accepted it.

- [x] **Step 6: Implement mail sender without a new package**

Add non-secret environment defaults:

```dotenv
SELLER_APP_ORIGIN=http://app.conwix.localhost
REGISTRATION_DOCUMENTS_VERSION=2026-09-02
```

Bind both strings in `services.yaml`. `MailRegistrationEmailSender` builds the confirmation URL as:

```php
$url = rtrim($sellerAppOrigin, '/').'/confirm-email?token='.rawurlencode($secret->plainText());
```

Use `Symfony\Component\Mailer\MailerInterface` and `Symfony\Component\Mime\Email`. Do not set `From` in code; `mailer.yaml` owns it. The free-email subject/body contains the URL; the taken-email subject/body says the account already exists and does not contain a token.

- [x] **Step 7: Implement request/controller and public access rule**

`SelfRegistrationRequest::fromJson()` accepts only server-relevant values and exposes `email`, `password`, `companyName`, `legalConsent`; it does not accept a document version. The controller hashes only after all request validation succeeds, passes the server-injected version and a single `new DateTimeImmutable()` to the action, and always returns the same `202` response for both valid-email branches.

Add exact public access rule before protected `/api/companies/` rules:

```yaml
- { path: ^/api/auth/sign-up$, roles: PUBLIC_ACCESS }
```

Do not add rate limiting or captcha in Stage 1; those are Stage 2 and must run before hashing then.

- [x] **Step 8: Run focused tests GREEN**

```bash
docker compose exec php-cli php bin/phpunit tests/Functional/Identity/SelfRegistrationControllerTest.php
docker compose exec php-cli php bin/phpunit tests/Functional/Identity/ClientAccountsControllerTest.php
```

Expected: both pass, including mail queue and audit assertions.

- [x] **Step 9: Commit Task 2**

```bash
git add api/.env api/config api/src/Identity api/tests/Functional/Identity
git commit -m "Открывает самостоятельную регистрацию аккаунта"
```

### Task 3: Повторная отправка подтверждения новой строкой

**Files:**
- Create: `api/src/Identity/Application/ResendEmailVerificationAction.php`
- Create: `api/src/Identity/Ui/Request/ResendEmailVerificationRequest.php`
- Create: `api/src/Identity/Ui/Controller/ResendEmailVerificationController.php`
- Modify: `api/src/Identity/Domain/EmailVerificationTokenRepository.php`
- Modify: `api/src/Identity/Infrastructure/Repository/DoctrineEmailVerificationTokenRepository.php`
- Modify: `api/config/packages/security.yaml`
- Test: `api/tests/Functional/Identity/ResendEmailVerificationControllerTest.php`

**Interfaces:**
- Produces: `ResendEmailVerificationAction::__invoke(string $email, DateTimeImmutable $now): void`.
- Uses: `UserRepository::findByEmail(string): ?User` only after the client explicitly requests resend; signup itself remains insert-first.
- Uses: `EmailVerificationTokenRepository::add(EmailVerificationToken): void`.

- [x] **Step 1: Write failing tests for append-only resend**

Create an unconfirmed user, call `POST /api/auth/email-verification/resend` twice with its email and assert two distinct token rows exist with unchanged first-row fields. Assert two confirmation emails were queued.

Call the same endpoint for an unknown email and a confirmed email; assert the exact status/body matches the unconfirmed case and no token is created. All three branches must perform one synchronous SMTP call; unknown receives a neutral message without a token. Do not expose whether the email exists or is already confirmed through status or timing.

- [x] **Step 2: Run the test and confirm RED**

```bash
docker compose exec php-cli php bin/phpunit tests/Functional/Identity/ResendEmailVerificationControllerTest.php
```

Expected: route/action absent.

- [x] **Step 3: Implement the action and endpoint**

For an unconfirmed user, generate a fresh secret, persist a new `EmailVerificationToken`, then send confirmation. Never mutate or invalidate older rows. A confirmed user receives the already-registered reminder; an unknown address receives a neutral message without a token. Use the same generic `202` body and one synchronous SMTP call in every branch.

Add:

```yaml
- { path: ^/api/auth/email-verification/resend$, roles: PUBLIC_ACCESS }
```

Rate limiting remains intentionally absent until Stage 2.

- [x] **Step 4: Run the test GREEN**

```bash
docker compose exec php-cli php bin/phpunit tests/Functional/Identity/ResendEmailVerificationControllerTest.php
```

Expected: pass; two resends produce two append-only rows, and every valid-email branch performs one non-queued SMTP call.

- [x] **Step 5: Commit Task 3**

```bash
git add api/src/Identity api/config/packages/security.yaml api/tests/Functional/Identity/ResendEmailVerificationControllerTest.php
git commit -m "Добавляет повторную отправку подтверждения email"
```

### Task 4: Одноразовое подтверждение, аудит и открытие сессии

**Files:**
- Create: `api/src/Identity/Domain/ValueObject/EmailConfirmationOutcome.php`
- Create: `api/src/Identity/Application/EmailConfirmationResult.php`
- Create: `api/src/Identity/Application/ConfirmEmailAction.php`
- Modify: `api/src/Identity/Domain/EmailVerificationTokenRepository.php`
- Modify: `api/src/Identity/Infrastructure/Repository/DoctrineEmailVerificationTokenRepository.php`
- Modify: `api/src/Identity/Domain/AuditAction.php`
- Create: `api/src/Identity/Ui/Request/ConfirmEmailRequest.php`
- Create: `api/src/Identity/Ui/Response/EmailConfirmationResponse.php`
- Create: `api/src/Identity/Ui/Controller/ConfirmEmailController.php`
- Modify: `api/config/packages/security.yaml`
- Test: `api/tests/Integration/Identity/ConfirmEmailActionTest.php`
- Test: `api/tests/Functional/Identity/ConfirmEmailControllerTest.php`

**Interfaces:**
- Produces enum: `EmailConfirmationOutcome::Confirmed`, `Expired`, `AlreadyConsumed`.
- Produces: `EmailVerificationTokenRepository::confirm(string $tokenHash, DateTimeImmutable $now): EmailConfirmationResult`.
- Produces: `ConfirmEmailAction::__invoke(EmailVerificationSecret $secret, DateTimeImmutable $now): EmailConfirmationResult`.
- `EmailConfirmationResult` contains outcome and `?User`; `User` is non-null only for `Confirmed` so the controller can open a session.

- [x] **Step 1: Write failing integration tests for the conditional transition**

Cover:

1. valid token sets both `email_verification_token.consumed_at` and `user.email_confirmed_at` and inserts exactly one `user.email_confirmed` audit row with the user actor;
2. the same token called twice returns `Confirmed` then `AlreadyConsumed`, leaving one audit row;
3. expired token returns `Expired`, changes no row and writes no audit;
4. two different live tokens for one user: first returns `Confirmed`, second cannot create another confirmation event and returns `AlreadyConsumed` after consuming or recognizing the already-confirmed user atomically.

- [x] **Step 2: Run integration test and confirm RED**

```bash
docker compose exec php-cli php bin/phpunit tests/Integration/Identity/ConfirmEmailActionTest.php
```

Expected: repository transition and outcome types absent.

- [x] **Step 3: Implement one database transaction with the condition inside UPDATE**

The winning statement must contain both conditions:

```sql
UPDATE email_verification_token
SET consumed_at = :now
WHERE token_hash = :token_hash
  AND consumed_at IS NULL
  AND expires_at > :now
RETURNING user_id
```

When it returns a user, update the user only if still unconfirmed:

```sql
UPDATE "user"
SET email_confirmed_at = :now
WHERE id = :user_id
  AND email_confirmed_at IS NULL
RETURNING email
```

Only when the second update returns an email, persist the following record and flush within the same DB transaction:

```php
AuditRecord::record(
    companyId: $companyId,
    actorUserId: $userId,
    action: AuditAction::UserEmailConfirmed,
    subjectId: $userId,
    previousValue: null,
    newValue: $now->format(DATE_ATOM),
    occurredAt: $now,
);
```

Resolve `$companyId` in the same transaction by joining `company_member` for the owner membership created by signup. If the first update affects zero rows, read only that token row to distinguish expired/unknown from consumed; unknown maps to `Expired` so the API does not gain a token oracle.

- [x] **Step 4: Write failing HTTP/session tests**

Post a valid secret to `/api/auth/email-verification/confirm`, assert `200`, `outcome = confirmed`, then call `/api/auth/me` with the same browser and assert success without a password login.

Post the same secret again and assert `409` with `outcome = already_consumed`. Post an expired/unknown secret and assert `410` with `outcome = expired`.

- [x] **Step 5: Implement controller and programmatic login**

Add the public rule:

```yaml
- { path: ^/api/auth/email-verification/confirm$, roles: PUBLIC_ACCESS }
```

On `Confirmed`, call:

```php
$security->login($result->user, firewallName: 'api');
```

Return only outcome and next route metadata needed by Stage 3; never return the token. The backend next target is the seller onboarding route, not a company dashboard.

- [x] **Step 6: Run focused tests GREEN**

```bash
docker compose exec php-cli php bin/phpunit tests/Integration/Identity/ConfirmEmailActionTest.php
docker compose exec php-cli php bin/phpunit tests/Functional/Identity/ConfirmEmailControllerTest.php
```

Expected: pass; duplicate confirmation produces one audit row and the first call opens a working session.

- [x] **Step 7: Commit Task 4**

```bash
git add api/src/Identity api/config/packages/security.yaml api/tests/Integration/Identity/ConfirmEmailActionTest.php api/tests/Functional/Identity/ConfirmEmailControllerTest.php
git commit -m "Подтверждает email одноразовым токеном"
```

### Task 5: Запрет входа неподтверждённого пользователя на каждой аутентификации

**Files:**
- Create: `api/src/Identity/Infrastructure/Security/EmailConfirmedUserChecker.php`
- Modify: `api/config/packages/security.yaml`
- Modify: `api/tests/Functional/Identity/AuthControllerTest.php`
- Test: `api/tests/Functional/Identity/UnconfirmedSessionTest.php`

**Interfaces:**
- Produces: `EmailConfirmedUserChecker implements UserCheckerInterface`.
- Configures the checker only on firewalls `api` and `extension`; the administrator firewall remains unaffected.

- [x] **Step 1: Write failing real-login test**

Persist an unconfirmed user with a valid password and POST the correct credentials to `/api/auth/login`. Assert `401` and then `/api/auth/me` also returns `401`.

Do not test configuration text; exercise HTTP as required by Stage 1 DoD.

- [x] **Step 2: Write failing restored-session test**

Use `KernelBrowser::loginUser($unconfirmedUser, 'api')`, then request `/api/auth/me`. Assert the session-restored principal is rejected with `401`. This proves the check is not merely in JSON login.

Keep/extend a confirmed-user control test proving normal login still succeeds.

- [x] **Step 3: Run tests and confirm RED**

```bash
docker compose exec php-cli php bin/phpunit tests/Functional/Identity/AuthControllerTest.php
docker compose exec php-cli php bin/phpunit tests/Functional/Identity/UnconfirmedSessionTest.php
```

Expected: unconfirmed user currently authenticates.

- [x] **Step 4: Implement and configure the checker**

`checkPreAuth()` must ignore non-`User` principals and throw a Symfony account-status authentication exception when `emailConfirmedAt()` is null. `checkPostAuth()` is a no-op.

Configure:

```yaml
extension:
    user_checker: App\Identity\Infrastructure\Security\EmailConfirmedUserChecker
api:
    user_checker: App\Identity\Infrastructure\Security\EmailConfirmedUserChecker
```

Do not add it to `admin`; `Administrator` is a different principal by ADR-007.

- [x] **Step 5: Ensure the failure response does not enumerate account state**

The login failure handler must continue returning the generic external code/message used for wrong password and unknown email. If Symfony exposes the custom checker message, map it back to the existing `invalid_credentials` response and rely on resend/confirmation pages for recovery.

- [x] **Step 6: Run focused tests GREEN**

```bash
docker compose exec php-cli php bin/phpunit tests/Functional/Identity/AuthControllerTest.php
docker compose exec php-cli php bin/phpunit tests/Functional/Identity/UnconfirmedSessionTest.php
```

Expected: all confirmed controls pass; both unconfirmed paths return 401.

- [x] **Step 7: Commit Task 5**

```bash
git add api/src/Identity/Infrastructure/Security api/config/packages/security.yaml api/tests/Functional/Identity
git commit -m "Закрывает вход до подтверждения email"
```

### Task 6: Признак подтверждения в списке аккаунтов администратора

**Files:**
- Modify: `api/src/Identity/Infrastructure/Query/AllCompaniesForAdminQuery.php`
- Modify: `api/src/Identity/Infrastructure/Query/AdminCompanyRow.php`
- Modify: `api/src/Identity/Ui/Response/AdminCompanyResponse.php`
- Modify: `api/src/Identity/Ui/Controller/ListClientAccountsController.php`
- Modify: `api/tests/Functional/Identity/ClientAccountsControllerTest.php`

**Interfaces:**
- Adds: `AdminCompanyRow::$hasConfirmedUser: bool`.
- Adds: JSON field `hasConfirmedUser` to `AdminCompanyResponse`.

- [x] **Step 1: Write failing list test**

Create one company with a confirmed member and one with only an unconfirmed member. GET the admin list and assert `hasConfirmedUser` is true/false for the corresponding rows. Ensure the test does not rely on ordering alone; index returned items by company id.

- [x] **Step 2: Run test and confirm RED**

```bash
docker compose exec php-cli php bin/phpunit tests/Functional/Identity/ClientAccountsControllerTest.php
```

Expected: response field absent.

- [x] **Step 3: Extend the single DBAL list query**

Add a correlated `EXISTS`, not a per-row repository call:

```sql
EXISTS (
    SELECT 1
    FROM company_member cm
    JOIN "user" u ON u.id = cm.user_id
    WHERE cm.company_id = c.id
      AND u.email_confirmed_at IS NOT NULL
) AS has_confirmed_user
```

Map PostgreSQL boolean safely (`true`, `false`, `'1'`, `'0'` as actually returned by the configured DBAL driver) and expose it in the response.

- [x] **Step 4: Run test GREEN**

```bash
docker compose exec php-cli php bin/phpunit tests/Functional/Identity/ClientAccountsControllerTest.php
```

Expected: pass with no extra queries per company.

- [x] **Step 5: Commit Task 6**

```bash
git add api/src/Identity/Infrastructure/Query api/src/Identity/Ui api/tests/Functional/Identity/ClientAccountsControllerTest.php
git commit -m "Показывает подтверждение email в списке аккаунтов"
```

### Task 7: Консервативная ручная уборка брошенных аккаунтов

**Files:**
- Create: `api/src/Identity/Domain/UnconfirmedAccountCleaner.php`
- Create: `api/src/Identity/Application/PurgeUnconfirmedAccountsAction.php`
- Create: `api/src/Identity/Infrastructure/Repository/DoctrineUnconfirmedAccountCleaner.php`
- Create: `api/src/Identity/Ui/Command/PurgeUnconfirmedAccountsCommand.php`
- Test: `api/tests/Integration/Identity/PurgeUnconfirmedAccountsCommandTest.php`

**Interfaces:**
- Produces: `UnconfirmedAccountCleaner::purgeCreatedBefore(DateTimeImmutable $cutoff): int`.
- Produces: command `app:identity:purge-unconfirmed-accounts` with no destructive options and a fixed 30-day cutoff computed from current UTC time.

- [x] **Step 1: Write failing eligibility/protection tests**

Create a deletable account older than 30 days with only unconfirmed users and no business data; assert command success, company/member/user/token/registration-audit removal, and deleted count `1`.

Using separate test cases/data provider, prove the company is retained when it has any one of:

```text
confirmed user
marketplace_account
extension_token
fresh live confirmation token
sales_fact
marketplace_expense_fact
marketplace_raw_document
marketplace_listing / marketplace_listing_cost / marketplace_listing_price
tracked_sku / price_observation
marketplace_posting_status / marketplace_return_fact
created_at exactly 30 days ago or newer
```

Also assert a shared user who still has membership in a retained company is not deleted.

- [x] **Step 2: Run the test and confirm RED**

```bash
docker compose exec php-cli php bin/phpunit tests/Integration/Identity/PurgeUnconfirmedAccountsCommandTest.php
```

Expected: command/cleaner absent.

- [x] **Step 3: Implement one conservative SQL transaction**

The eligibility CTE must put every condition inside SQL:

```sql
WITH eligible_company AS (
    SELECT c.id
    FROM company c
    WHERE c.created_at < :cutoff
      AND NOT EXISTS (
          SELECT 1 FROM company_member cm
          JOIN "user" u ON u.id = cm.user_id
          WHERE cm.company_id = c.id AND u.email_confirmed_at IS NOT NULL
      )
      AND NOT EXISTS (SELECT 1 FROM marketplace_account ma WHERE ma.company_id = c.id)
      AND NOT EXISTS (SELECT 1 FROM extension_token et WHERE et.company_id = c.id)
      AND NOT EXISTS (SELECT 1 FROM sales_fact sf WHERE sf.company_id = c.id)
      AND NOT EXISTS (SELECT 1 FROM marketplace_expense_fact mef WHERE mef.company_id = c.id)
      AND NOT EXISTS (SELECT 1 FROM marketplace_raw_document mrd WHERE mrd.company_id = c.id)
      AND NOT EXISTS (SELECT 1 FROM marketplace_posting_status mps WHERE mps.company_id = c.id)
      AND NOT EXISTS (SELECT 1 FROM marketplace_return_fact mrf WHERE mrf.company_id = c.id)
      AND NOT EXISTS (SELECT 1 FROM marketplace_listing ml WHERE ml.company_id = c.id)
      AND NOT EXISTS (SELECT 1 FROM marketplace_listing_cost mlc WHERE mlc.company_id = c.id)
      AND NOT EXISTS (SELECT 1 FROM marketplace_listing_price mlp WHERE mlp.company_id = c.id)
      AND NOT EXISTS (SELECT 1 FROM tracked_sku ts WHERE ts.company_id = c.id)
      AND NOT EXISTS (SELECT 1 FROM price_observation po WHERE po.company_id = c.id)
    FOR UPDATE
)
```

After synchronization with the current `master`, `marketplace_posting_status`
and `marketplace_return_fact` are part of the branch base. Their explicit
`NOT EXISTS` clauses and protection cases are therefore included in Stage 1.

Use data-modifying CTEs in the same statement/transaction to delete email tokens, registration audit rows, memberships, orphaned unconfirmed users and finally companies, returning the deleted company count. Never dynamically discover tables from `information_schema`; the protected data set is an explicit reviewed contract.

The class docblock must link to CLAUDE.md §1 and ADR-021 and explain the deliberate operational cross-module read: this is the one cleanup eligibility query spanning Identity/Ingestion/PriceMonitoring, not a reusable way for seller UI to read foreign modules. Keep the class outside every layer reachable from HTTP; only the console action receives it. If Deptrac needs a narrow operational layer, add one class-level collector and grant it only to the purge action/command.

- [x] **Step 4: Implement the manual command**

Compute:

```php
$cutoff = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$deleted = ($action)($cutoff->modify('-30 days'));
```

Print the exact cutoff and number deleted. Do not ask for confirmation inside the command: eligibility is conservative and the command is itself the explicit human action. Do not schedule it.

- [x] **Step 5: Run test GREEN**

```bash
docker compose exec php-cli php bin/phpunit tests/Integration/Identity/PurgeUnconfirmedAccountsCommandTest.php
```

Expected: only the fully empty, old, unconfirmed account is removed.

- [x] **Step 6: Commit Task 7**

```bash
git add api/src/Identity api/deptrac.php api/tests/Integration/Identity/PurgeUnconfirmedAccountsCommandTest.php
git commit -m "Добавляет ручную уборку неподтвержденных аккаунтов"
```

### Task 8: Контракт, структура и эксплуатационные проверки Stage 1

**Files:**
- Modify: `docs/structure.md`
- Modify: `docs/operations-checklist.md`
- Modify: `packages/api-schema/openapi.json` (generated)
- Modify: `packages/api-schema/src/schema.d.ts` (generated)
- Test: existing schema/type checks

**Interfaces:**
- Documents the public signup/resend/confirm endpoints and the purge command.
- Generates TypeScript API types; Stage 3 will consume them, but no seller UI is added now.

- [x] **Step 1: Update living documentation**

In `docs/structure.md`, add focused entries for registration Application actions, email notification adapter, security checker, token repository/entity and purge command; do not enumerate every filename if the existing section documents directories by responsibility.

In `docs/operations-checklist.md`, add:

```markdown
- Перед открытием регистрации: MAILER_DSN и MAILER_FROM заданы на prod; письмо подтверждения фактически дошло до внешнего ящика.
- Перед открытием регистрации: SPF и DKIM домена отправителя проверены; письмо не попадает в спам.
- Раз в месяц: измерить долю self-signup аккаунтов, дошедших до email confirmation; резкое падение разделять на доставляемость и captcha (после Stage 2).
- По мере накопления: вручную запускать app:identity:purge-unconfirmed-accounts и записывать число удалённых строк.
```

OAuth `404` release-check добавляется вместе со Stage 5, когда маршруты появятся; до этого проверять несуществующий код бессмысленно.

- [x] **Step 2: Generate and verify the API contract**

```bash
make api-doc-export
make api-types
make api-types-check
```

Expected: OpenAPI contains signup/resend/confirm request and response shapes, and generated TypeScript types are synchronized.

- [x] **Step 3: Run structural checks**

```bash
make structure-check
make deptrac
```

Expected: zero violations; the cleanup capability is unreachable from HTTP layers.

- [x] **Step 4: Commit Task 8**

```bash
git add docs/structure.md docs/operations-checklist.md packages/api-schema
git commit -m "Документирует эксплуатацию самостоятельной регистрации"
```

### Task 9: Полная самопроверка, внешний ревью-пакет и исправления

**Files:**
- Modify: any Stage 1 file only when a verification/review finding is accepted
- Generated review artifacts under `var/review/` are not committed

**Interfaces:**
- Produces one review package named `Stage 1: самостоятельная регистрация и подтверждение email`.

- [x] **Step 1: Run the complete backend test and static-analysis suite**

```bash
make test
make lint
make stan
make deptrac
make structure-check
make api-types-check
```

Expected: every command exits 0 with no new baseline/suppression.

- [x] **Step 2: Run the mandatory clean-database migration check**

```bash
make db-rebuild-check
```

Expected: empty dev/test databases rebuild, the single Stage 1 migration applies, schema validates and all backend tests pass.

- [x] **Step 3: Perform the fixed CLAUDE.md self-review in order**

Record evidence for all 14 items. For this stage pay special attention to:

```text
1  no company-scoped repository read without companyId except the explicitly documented cleanup/auth/pre-auth lifecycle boundary
6  no find-then-insert in signup; token consumption condition is inside UPDATE
7  token.user_id index exists in the same migration
8  mail/list/cleanup code contains no queries in loops
9  HTTP tests cover actual authentication and session restore
10 Deptrac is green and cleanup is not injectable into seller UI
13 exactly one migration and db-rebuild-check green
14 no plain token, password, mail secret or environment secret in diff/logs
```

- [x] **Step 4: Generate both mandatory external reviews**

Stage 1 affects authentication, schema and personal data, so run both roles:

```bash
make review TASK="Stage 1: самостоятельная регистрация и подтверждение email"
```

Expected: both `var/review/codex.md` and `var/review/codex-defects.md` contain completed reviews, not timeouts/truncated outputs.

- [x] **Step 5: Classify every finding and fix accepted defects**

For each finding, record one of:

```text
accepted defect/rule violation -> add failing regression test, confirm RED, implement fix, confirm GREEN
rejected ADR alternative -> cite ADR-021 section
rejected taste-only suggestion -> one-line concrete reason
rules gap -> update the relevant rule/document separately, then re-review
```

Repeat full focused tests and both reviews until no accepted finding remains. Record how many unique accepted findings the defects-role added beyond the rules-role.

- [x] **Step 6: Keep ADR-021 Proposed and prepare the Stage 1 report**

Do not mark ADR-021 Accepted: captcha and onboarding portions remain unimplemented in Stages 2–4. The report must list Stage 2 as the next package and use the repository format:

```text
Задача / Сделано / Файлы / Ревью / Отклонено / Проверка / Открыто
```

- [x] **Step 7: Commit review fixes, if any**

```bash
git add -A
git commit -m "Исправляет замечания ревью регистрации"
```

Skip the commit only when no files changed after review.
