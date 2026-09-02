# Signup Stage 3 UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Реализовать браузерный поток самостоятельной регистрации, подтверждения email и входа в защищённый стартовый экран онбординга с невидимой Yandex SmartCaptcha.

**Architecture:** UI остаётся тонким слоем над уже существующими Stage 1/2 API. Оркестрация captcha выделяется в чистый автомат состояний, HTTP-вызовы — в React Query hooks, а страницы отвечают только за форму, безопасные сообщения и маршрутизацию. Публичный client key поступает только через GitHub repository variable и build-time переменную Vite; server key во frontend не передаётся.

**Tech Stack:** React 19, TypeScript strict, React Router, React Hook Form, TanStack Query, Vite, Vitest, MSW, `@yandex/smart-captcha@2.9.1`, Docker Compose, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-09-02-signup-stage3-ui-design.md`

## Global Constraints

- Не читать, не печатать и не передавать во frontend `SMARTCAPTCHA_SERVER_KEY`.
- Использовать только сгенерированные OpenAPI-типы для HTTP-ответов; не создавать дубли DTO.
- Не вызывать `fetch` вне `apps/seller/src/api` и не обходить существующий API client.
- Не подключать реальную Yandex SmartCaptcha в автоматических тестах.
- Не раскрывать существование email ни в тексте ответа, ни в URL/localStorage.
- Не добавлять обход captcha для production/E2E.
- Не открывать регистрацию публично, пока Google Fonts на юридических страницах не удалены либо политика трансграничной передачи не согласована.

## Task 1: Pin SmartCaptcha and Wire the Public Client Key

**Files:**

- Modify: `apps/seller/package.json`
- Modify: `apps/seller/package-lock.json`
- Modify: `apps/seller/vite.config.ts`
- Modify: `docker-compose.yml`
- Modify: `.github/workflows/ci.yml`
- Create: `bin/tests/smartcaptcha-client-config.test.sh`

- [ ] **Step 1: Add a failing configuration contract test**

Create `bin/tests/smartcaptcha-client-config.test.sh` with strict shell options and checks that:

```bash
rg -q 'SMARTCAPTCHA_CLIENT_KEY:.*vars\.SMARTCAPTCHA_CLIENT_KEY' .github/workflows/ci.yml
rg -q 'VITE_SMARTCAPTCHA_CLIENT_KEY.*SMARTCAPTCHA_CLIENT_KEY' docker-compose.yml
rg -q 'VITE_SMARTCAPTCHA_CLIENT_KEY' apps/seller/vite.config.ts
! rg -q 'SMARTCAPTCHA_SERVER_KEY' apps/seller docker-compose.yml .github/workflows/ci.yml
```

Mark it executable:

```bash
chmod +x bin/tests/smartcaptcha-client-config.test.sh
```

Run:

```bash
bash bin/tests/smartcaptcha-client-config.test.sh
```

Expected: FAIL because the mappings and Vite guard do not exist yet.

- [ ] **Step 2: Verify and install the approved exact package version**

Run:

```bash
docker compose exec node-seller npm view @yandex/smart-captcha@2.9.1 version peerDependencies dependencies scripts --json
docker compose exec node-seller npm install --save-exact @yandex/smart-captcha@2.9.1
```

Expected: version `2.9.1`, React 19-compatible peer range, no runtime dependency or install lifecycle script; lockfile updated with the exact package.

- [ ] **Step 3: Map the GitHub variable into the frontend build container**

At workflow top level add:

```yaml
env:
  SMARTCAPTCHA_CLIENT_KEY: ${{ vars.SMARTCAPTCHA_CLIENT_KEY }}
```

In the `node-seller` service add:

```yaml
environment:
  HOME: /tmp
  VITE_SMARTCAPTCHA_CLIENT_KEY: ${SMARTCAPTCHA_CLIENT_KEY:-}
```

The server key must not appear in either file.

- [ ] **Step 4: Fail production builds when the public key is absent or malformed**

Convert `apps/seller/vite.config.ts` to callback form and validate only in build mode:

```ts
const SMARTCAPTCHA_CLIENT_KEY_PATTERN = /^ysc1_[A-Za-z0-9_-]+$/;

export default defineConfig(({ command }) => {
  const clientKey = process.env.VITE_SMARTCAPTCHA_CLIENT_KEY ?? "";

  if (command === "build" && !SMARTCAPTCHA_CLIENT_KEY_PATTERN.test(clientKey)) {
    throw new Error("VITE_SMARTCAPTCHA_CLIENT_KEY must contain a valid public SmartCaptcha client key");
  }

  return {
    // preserve the existing plugins, aliases and test configuration
  };
});
```

Do not validate during `vitest`, so unit tests remain independent from deployment configuration.

- [ ] **Step 5: Prove red/green build behavior and the config contract**

Run:

```bash
docker compose exec -e VITE_SMARTCAPTCHA_CLIENT_KEY= node-seller npm run build
```

Expected: FAIL with the explicit Vite configuration error.

Run:

```bash
docker compose exec -e VITE_SMARTCAPTCHA_CLIENT_KEY=ysc1_stage3_build_test_only node-seller npm run build
bash bin/tests/smartcaptcha-client-config.test.sh
```

Expected: both PASS.

- [ ] **Step 6: Commit the dependency/config slice**

```bash
git add apps/seller/package.json apps/seller/package-lock.json apps/seller/vite.config.ts docker-compose.yml .github/workflows/ci.yml bin/tests/smartcaptcha-client-config.test.sh
git commit -m "Настраивает клиентский ключ SmartCaptcha"
```

## Task 2: Build the Registration State and API Model

**Files:**

- Create: `apps/seller/src/features/auth/lib/captchaFlow.ts`
- Create: `apps/seller/src/features/auth/lib/captchaFlow.test.ts`
- Create: `apps/seller/src/features/auth/lib/registrationPayload.ts`
- Create: `apps/seller/src/features/auth/lib/registrationPayload.test.ts`
- Create: `apps/seller/src/features/auth/lib/signupFailure.ts`
- Create: `apps/seller/src/features/auth/lib/signupFailure.test.ts`
- Create: `apps/seller/src/features/auth/model/useSignUp.ts`
- Create: `apps/seller/src/features/auth/model/useSignUp.test.ts`
- Create: `apps/seller/src/features/auth/model/useResendConfirmation.ts`
- Create: `apps/seller/src/features/auth/model/useResendConfirmation.test.ts`

- [ ] **Step 1: Write failing tests for captcha orchestration**

Define the public model contract in tests before implementation:

```ts
type CaptchaFlowState =
  | { status: "idle" }
  | { status: "executing"; request: RegistrationPayload }
  | { status: "submitting"; request: RegistrationPayload }
  | { status: "challenge"; request: RegistrationPayload }
  | { status: "failed"; message: string };
```

Test pure transitions for:

- valid submit: `idle -> executing` while retaining the normalized request;
- challenge visibility: `executing -> challenge`;
- captcha success: `executing|challenge -> submitting`;
- captcha/network rejection: `executing|challenge|submitting -> failed`;
- reset: any terminal state `-> idle`;
- duplicate/stale success while `submitting` is ignored.

Run:

```bash
docker compose exec node-seller npm test -- --run src/features/auth/lib/captchaFlow.test.ts
```

Expected: FAIL because the model does not exist.

- [ ] **Step 2: Implement the pure captcha state machine**

Create named functions rather than embedding transitions inside React callbacks:

```ts
startCaptcha(state, request): CaptchaFlowState
showChallenge(state): CaptchaFlowState
acceptCaptcha(state): CaptchaFlowState
failCaptcha(state, message): CaptchaFlowState
resetCaptcha(): CaptchaFlowState
```

Keep the pending registration request in memory only. Return the unchanged state for impossible or duplicate transitions.

- [ ] **Step 3: Write failing payload-normalization tests**

Cover this exact input/output contract:

```ts
type RegistrationFormValues = {
  email: string;
  password: string;
  companyName: string;
  legalConsent: boolean;
};

type RegistrationPayload = {
  email: string;
  password: string;
  companyName: string;
  legalConsent: true;
};
```

Assert that email and company name are trimmed, password is not altered, and unchecked consent cannot produce a payload.

The API request is derived from the generated OpenAPI request body rather than duplicated:

```ts
type SignUpRequest = NonNullable<
  paths['post_identity_self_registration']['requestBody']
>['content']['application/json'];
```

- [ ] **Step 4: Implement payload normalization and local rules**

Export constants for password minimum `12` and company-name maximum `255`. Use React Hook Form rules for immediate field feedback, but keep `toRegistrationPayload` as the single boundary that produces the API request.

- [ ] **Step 5: Write failing safe-error mapping tests**

Test exact mappings for:

- field codes `email_invalid`, `password_too_short`, `company_name_invalid`, `legal_consent_required`;
- `captcha_invalid` -> retry with a new token;
- HTTP `429` -> fixed later message;
- HTTP `503` -> fixed unavailable message;
- unknown network/SMTP/server failures -> generic safe message with no raw backend detail.

- [ ] **Step 6: Implement the failure mapper and API hooks**

Implement `useSignUp` using the existing unauthenticated `apiPost` pattern and generated `SelfRegistrationResponse`. Its request boundary combines the in-memory payload with the widget value as `{ email, password, companyName, legalConsent, captchaToken }`; never normalize or reuse `captchaToken`. Implement `useResendConfirmation` against `/api/auth/email-verification/resend`. Hooks return typed results and errors; pages own navigation and presentation.

- [ ] **Step 7: Add typed MSW contract tests for signup and resend**

With `http` and `server` from `apps/seller/tests/msw/server.ts`, exercise the real `apiPost` client at an absolute `http://localhost/api/...` URL (the established Node/MSW convention) and prove that:

- signup sends the exact camelCase OpenAPI body including a non-empty `captchaToken` and accepts the contract `202` response;
- resend sends only `{ email }` and accepts the same neutral contract `202` response;
- a typed `422` becomes `ApiError` and reaches the safe error mapper.

The hook itself must keep the same-origin relative production path. Do not add a test-only parameter to production code, replace global `fetch`, or construct `Response` manually.

- [ ] **Step 8: Run focused model/API tests and typecheck**

```bash
docker compose exec node-seller npm test -- --run \
  src/features/auth/lib/captchaFlow.test.ts \
  src/features/auth/lib/registrationPayload.test.ts \
  src/features/auth/lib/signupFailure.test.ts \
  src/features/auth/model/useSignUp.test.ts \
  src/features/auth/model/useResendConfirmation.test.ts
docker compose exec node-seller npm run typecheck
```

Expected: PASS.

- [ ] **Step 9: Commit the registration model slice**

```bash
git add apps/seller/src/features/auth/lib apps/seller/src/features/auth/model
git commit -m "Добавляет модель самостоятельной регистрации"
```

## Task 3: Implement Signup, Neutral Success, and Resend Screens

**Files:**

- Create: `apps/seller/src/features/auth/ui/LegalConsentField.tsx`
- Create: `apps/seller/src/features/auth/ui/LegalConsentField.test.tsx`
- Create: `apps/seller/src/features/auth/ui/EmailSentPage.tsx`
- Create: `apps/seller/src/features/auth/ui/EmailSentPage.test.tsx`
- Create: `apps/seller/src/features/auth/ui/SignUpPage.tsx`
- Create: `apps/seller/src/features/auth/ui/ResendConfirmationPage.tsx`
- Modify: `apps/seller/src/features/auth/ui/LoginPage.tsx`

- [ ] **Step 1: Write failing static-markup tests for legal consent**

Use `renderToStaticMarkup` because Vitest runs in the Node environment. Assert that the field:

- renders an unchecked checkbox;
- links to all three exact URLs:
  - `https://conwix.com/privacy.html`
  - `https://conwix.com/oferta.html`
  - `https://conwix.com/personal-data.html`;
- opens each external document in a new tab with `rel="noreferrer"`;
- provides one readable consent sentence and an accessible checkbox label.

Run:

```bash
docker compose exec node-seller npm test -- --run src/features/auth/ui/LegalConsentField.test.tsx
```

Expected: FAIL because the component does not exist.

- [ ] **Step 2: Implement the legal-consent field**

Keep the checkbox controlled by React Hook Form. Do not pre-check it and do not collapse the three legal documents into one link.

- [ ] **Step 3: Write failing neutral-success markup tests**

Test that `EmailSentPage` says that instructions will be sent if the address can be used, contains no interpolated email, and links to `/resend-confirmation` and `/login`.

- [ ] **Step 4: Implement the neutral email-sent and resend pages**

`EmailSentPage` must not read email from query parameters, location state, or storage. `ResendConfirmationPage` contains only an email field, calls `useResendConfirmation`, and replaces the form with the same neutral result after every accepted API response.

- [ ] **Step 5: Implement the signup page around the state machine**

Use `useForm<RegistrationFormValues>` for:

- required valid email;
- password minimum 12 characters;
- required company name, maximum 255 characters;
- required, initially false legal consent.

Render the official component:

```tsx
<InvisibleSmartCaptcha
  key={captchaRevision}
  sitekey={import.meta.env.VITE_SMARTCAPTCHA_CLIENT_KEY}
  visible={flow.status === "executing" || flow.status === "challenge"}
  onChallengeVisible={() => transition(showChallenge)}
  onSuccess={(token) => submitWithCaptcha(token)}
  onTokenExpired={() => failAndReset(CAPTCHA_RETRY_MESSAGE)}
  onNetworkError={() => failAndReset(CAPTCHA_UNAVAILABLE_MESSAGE)}
  onJavascriptError={() => failAndReset(CAPTCHA_UNAVAILABLE_MESSAGE)}
  onChallengeHidden={() => failClosedChallengeIfPending()}
/>
```

Adapt prop names only if the pinned package declarations require it; preserve the approved behavior. Keep the current flow in a ref synchronized with React state so widget callbacks cannot observe stale state.

The submit sequence is exact:

1. locally validate and normalize the form;
2. enter `executing`, causing invisible captcha execution;
3. call `/api/auth/sign-up` only from `onSuccess(token)` and include that token once;
4. ignore duplicate/stale `onSuccess` callbacks once state is `submitting`;
5. after any API attempt, increment `captchaRevision` and return the widget to a fresh idle state;
6. on success, `navigate("/sign-up/email-sent", { replace: true })`.

Keep the provider shield/notice visible. Never implement a captcha bypass.

- [ ] **Step 6: Add the signup entry point to login**

Add a visible link from `LoginPage` to `/sign-up` without changing the existing login mutation.

- [ ] **Step 7: Run focused frontend checks**

```bash
docker compose exec node-seller npm test -- --run \
  src/features/auth/ui/LegalConsentField.test.tsx \
  src/features/auth/ui/EmailSentPage.test.tsx \
  src/features/auth/lib/captchaFlow.test.ts
docker compose exec node-seller npm run typecheck
```

Expected: PASS.

- [ ] **Step 8: Commit the signup UI slice**

```bash
git add apps/seller/src/features/auth/ui
git commit -m "Добавляет экран самостоятельной регистрации"
```

## Task 4: Implement Safe Email Confirmation

**Files:**

- Create: `apps/seller/src/features/auth/lib/confirmationToken.ts`
- Create: `apps/seller/src/features/auth/lib/confirmationToken.test.ts`
- Create: `apps/seller/src/features/auth/lib/confirmationOutcome.ts`
- Create: `apps/seller/src/features/auth/lib/confirmationOutcome.test.ts`
- Create: `apps/seller/src/features/auth/model/useConfirmEmail.ts`
- Create: `apps/seller/src/features/auth/model/useConfirmEmail.test.ts`
- Create: `apps/seller/src/features/auth/ui/ConfirmEmailPage.tsx`

- [ ] **Step 1: Write failing tests for extracting and erasing the token**

Define a pure helper that reads `token` from a supplied URL, and a browser helper that calls `history.replaceState` before any request. Tests cover:

- a non-empty token is returned only in memory;
- missing/blank token is rejected locally;
- the rewritten URL is exactly `/confirm-email`, with the complete query string removed;
- token never enters localStorage, sessionStorage, logs, or rendered markup.

Run:

```bash
docker compose exec node-seller npm test -- --run src/features/auth/lib/confirmationToken.test.ts
```

Expected: FAIL because the helpers do not exist.

- [ ] **Step 2: Implement token extraction and address-bar cleanup**

On first page evaluation:

```ts
const token = takeConfirmationToken(window.location.href);
window.history.replaceState(window.history.state, "", token.sanitizedPath);
```

Retain the token in component memory only for a transient retry. Perform cleanup before invoking the mutation.

- [ ] **Step 3: Write failing outcome-routing tests**

Use the generated `EmailConfirmationResponse` and test:

- `{ outcome: "confirmed", next: "/onboarding" }` -> `/onboarding`;
- a confirmed response with any other `next` value -> `/onboarding` rather than an open redirect;
- HTTP `409` -> login action;
- HTTP `410` -> resend-confirmation action;
- transient/network error -> retry action;
- malformed/unknown response -> generic safe failure.

- [ ] **Step 4: Add a typed MSW contract test for confirmation**

Use the real `apiPost` client with a typed handler for `/api/auth/email-verification/confirm`. Assert the exact `{ token }` body and the schema-valid `200`, `409`, and `410` responses. As in the other Node API tests, use an absolute localhost URL only in the test call; keep production same-origin. Do not manually construct `Response`.

- [ ] **Step 5: Implement the confirmation mutation hook**

Post the in-memory token to `/api/auth/email-verification/confirm`. On successful confirmation, await invalidation of `authQueryKey()` before navigation so a cached unauthenticated result cannot redirect the newly created session back to login.

- [ ] **Step 6: Implement the confirmation page**

The page begins confirmation once for a valid token and renders explicit states:

- pending: confirmation in progress;
- confirmed: navigate with replacement to `/onboarding`;
- already used (`409`): safe explanation and `/login` link;
- expired (`410`): safe explanation and `/resend-confirmation` link;
- transient failure: retry button using the token retained only in memory;
- missing token: invalid-link explanation with no API call.

Never render or log the token.

- [ ] **Step 7: Run focused confirmation tests and typecheck**

```bash
docker compose exec node-seller npm test -- --run \
  src/features/auth/lib/confirmationToken.test.ts \
  src/features/auth/lib/confirmationOutcome.test.ts \
  src/features/auth/model/useConfirmEmail.test.ts
docker compose exec node-seller npm run typecheck
```

Expected: PASS.

- [ ] **Step 8: Commit the confirmation slice**

```bash
git add apps/seller/src/features/auth/model apps/seller/src/features/auth/ui/ConfirmEmailPage.tsx
git commit -m "Добавляет подтверждение адреса в браузере"
```

## Task 5: Add Routes, Protected Onboarding Entry, and Documentation

**Files:**

- Create: `apps/seller/src/features/auth/ui/OnboardingStartPage.tsx`
- Create: `apps/seller/src/features/auth/ui/OnboardingStartPage.test.tsx`
- Modify: `apps/seller/src/app/Root.tsx`
- Modify: `docs/structure.md`
- Modify: `docs/patterns.md`
- Modify: `docs/operations-checklist.md`

- [ ] **Step 1: Write a failing static-markup test for the onboarding entry**

Assert that the page only announces the next step and names the three future Stage 4 inputs:

- store name;
- Ozon `Client-Id`;
- Ozon `Api-Key`.

Assert that it contains no credential fields, submit button, or company-shell navigation.

Run:

```bash
docker compose exec node-seller npm test -- --run src/features/auth/ui/OnboardingStartPage.test.tsx
```

Expected: FAIL because the page does not exist.

- [ ] **Step 2: Implement the Stage 3 onboarding entry screen**

Create a standalone authenticated page. It must not imply that credentials are currently collected or persisted.

- [ ] **Step 3: Register the approved routes**

Update `Root.tsx` with these exact routes:

```text
/sign-up
/sign-up/email-sent
/resend-confirmation
/confirm-email
/onboarding
```

Wrap only `/onboarding` in `RequireAuth`. Keep it outside the company/application shell because a newly confirmed user has no company selection yet. Existing authenticated routes and login behavior must remain unchanged.

- [ ] **Step 4: Document the new frontend structure and operating contract**

Update:

- `docs/structure.md` with the new auth/onboarding model and UI files;
- `docs/patterns.md` with the invisible-captcha state-machine pattern, one-use token rule, query-token erasure, and exact pinned-package rationale;
- `docs/operations-checklist.md` with `SMARTCAPTCHA_CLIENT_KEY` as a public GitHub repository variable, build fail-fast behavior, legal-page preflight, real-mailbox smoke, and rollback checks.

Explicitly record the rollout blocker: the legal pages currently load Google Fonts while the privacy text denies cross-border transfer; public opening waits for removal or policy reconciliation.

- [ ] **Step 5: Run the complete frontend and API-contract checks**

Run:

```bash
docker compose exec -e VITE_SMARTCAPTCHA_CLIENT_KEY=ysc1_stage3_build_test_only node-seller npm run lint
docker compose exec -e VITE_SMARTCAPTCHA_CLIENT_KEY=ysc1_stage3_build_test_only node-seller npm run format:check
docker compose exec -e VITE_SMARTCAPTCHA_CLIENT_KEY=ysc1_stage3_build_test_only node-seller npm run typecheck
docker compose exec -e VITE_SMARTCAPTCHA_CLIENT_KEY=ysc1_stage3_build_test_only node-seller npm test -- --run
docker compose exec -e VITE_SMARTCAPTCHA_CLIENT_KEY=ysc1_stage3_build_test_only node-seller npm run knip
docker compose exec -e VITE_SMARTCAPTCHA_CLIENT_KEY=ysc1_stage3_build_test_only node-seller npm run build
make api-types-check
bash bin/tests/smartcaptcha-client-config.test.sh
```

Expected: PASS with no OpenAPI regeneration or backend migration.

- [ ] **Step 6: Commit the integrated Stage 3 route/docs slice**

```bash
git add apps/seller/src/app/Root.tsx apps/seller/src/features/auth/ui/OnboardingStartPage.tsx apps/seller/src/features/auth/ui/OnboardingStartPage.test.tsx docs/structure.md docs/patterns.md docs/operations-checklist.md
git commit -m "Завершает браузерный поток регистрации"
```

## Task 6: Verify, Review, Publish, Deploy, and Smoke-Test

**Files:**

- Review: all Stage 3 changes against `docs/task/task-signup-onboarding-ozon-oauth.md`
- Review: `docs/superpowers/specs/2026-09-02-signup-stage3-ui-design.md`
- Generated locally: `var/review/package.md`, `var/review/codex.md`, `var/review/codex-defects.md`

- [ ] **Step 1: Configure the public GitHub repository variable without echoing it**

Load the approved public client key into a shell variable without command tracing, then pass it via stdin:

```bash
printf '%s' "$SMARTCAPTCHA_CLIENT_KEY_VALUE" | gh variable set SMARTCAPTCHA_CLIENT_KEY --repo Ortexx/conwix --body-file -
gh variable list --repo Ortexx/conwix | rg '^SMARTCAPTCHA_CLIENT_KEY\b'
```

Expected: the variable name is present. Do not print the value. If the repository slug differs, obtain it with `gh repo view --json nameWithOwner` and use that exact repository.

- [ ] **Step 2: Recreate the frontend container with a valid local build key and run the full local gate**

```bash
SMARTCAPTCHA_CLIENT_KEY=ysc1_stage3_build_test_only docker compose up -d --force-recreate node-seller
make ci-local
```

Expected: all backend, frontend, architecture, audit, build, and Playwright checks PASS. The existing Playwright suite must not contact Yandex.

- [ ] **Step 3: Build and run the required external review package**

```bash
make review TASK="Stage 3 signup UI: invisible SmartCaptcha, email confirmation, protected onboarding entry" ADR="0021"
```

Expected: both review commands complete and the package contains no unresolved blocking/high findings. Although the governance minimum for a new scenario is one external review, the repository's `make review` target deliberately runs both compliance and defect roles.

- [ ] **Step 4: Address findings with tests, then rerun both gates**

For every accepted finding, first add or strengthen a failing test, implement the smallest fix, then rerun:

```bash
SMARTCAPTCHA_CLIENT_KEY=ysc1_stage3_build_test_only docker compose up -d --force-recreate node-seller
make ci-local
make review TASK="Stage 3 signup UI after fixes: invisible SmartCaptcha, email confirmation, protected onboarding entry" ADR="0021"
```

Commit review-driven changes separately with a message describing the behavior fixed. Do not commit `var/review/*` unless repository policy explicitly requires it.

- [ ] **Step 5: Verify scope and create the PR**

Run:

```bash
git status --short
git diff --check master...HEAD
git diff --stat master...HEAD
git log --oneline master..HEAD
git push -u origin feat/signup-stage3-ui
gh pr create --base master --head feat/signup-stage3-ui --title "Stage 3: добавляет браузерную регистрацию" --body '## Что сделано
- регистрация с невидимой Yandex SmartCaptcha
- нейтральный email/resend flow и безопасное подтверждение
- защищённый стартовый экран onboarding
- build-time конфигурация публичного client key

## Проверка
- make ci-local
- make review

## Rollout blocker
- до публичного открытия убрать Google Fonts с юридических страниц либо согласовать политику трансграничной передачи'
```

Expected: clean worktree, focused diff, pushed branch, and an open PR.

- [ ] **Step 6: Wait for CI, merge, and deploy through the repository's normal workflow**

Run:

```bash
gh pr checks --watch
gh pr merge --merge --delete-branch
```

Use the existing documented production deploy command/workflow; do not invent a new path. Verify that the deployment includes the merged commit before smoke testing.

- [ ] **Step 7: Run non-secret production preflight**

Verify all legal pages return a successful response:

```bash
curl --fail --silent --show-error --location --output /dev/null https://conwix.com/privacy.html
curl --fail --silent --show-error --location --output /dev/null https://conwix.com/oferta.html
curl --fail --silent --show-error --location --output /dev/null https://conwix.com/personal-data.html
```

On production, verify only the presence/non-empty status of `SMARTCAPTCHA_SERVER_KEY`; never print its value. Verify the deployed browser bundle can load the Yandex widget and the SmartCaptcha provider shield/notice remains visible.

- [ ] **Step 8: Perform the required production browser smoke with a real external mailbox alias**

Use a fresh, actually available alias controlled by the operator. Exercise:

1. `/sign-up` loads all three legal links and an unchecked consent box;
2. valid submit executes invisible captcha and returns the neutral email-sent page;
3. the external mailbox receives the confirmation email;
4. opening the link removes `token` from the address bar before the request is observable;
5. confirmation opens the session and lands on protected `/onboarding`;
6. the page mentions future store name, `Client-Id`, and `Api-Key` only;
7. refresh retains the authenticated session;
8. resend produces the same neutral result;
9. expired/already-used links lead only to the safe login/resend actions;
10. logs and browser history contain no confirmation token or server key.

Do not add a scripted captcha bypass. Record the alias and result in the deployment log without recording credentials or token values.

- [ ] **Step 9: Clean up only the confirmed smoke tenant**

Resolve the exact tenant/user identifiers read-only, present them for a separate explicit destructive confirmation, then use the documented tenant cleanup procedure. Never delete by wildcard, email domain, or unresolved variable.

Stage 3 is complete only after CI, review, deploy, and the real-mailbox smoke pass. Public registration remains closed while the legal-page Google Fonts discrepancy is unresolved.
