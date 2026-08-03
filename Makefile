.DEFAULT_GOAL := help

# Оркестрационные цели (init, test, ci-local...) полагаются на порядок
# prerequisite-списка (down-clear до build, db-wait до миграций и т.д.).
# Без .NOTPARALLEL это верно только пока make запущен без -j; -j в
# MAKEFLAGS (например, унаследованный из окружения CI) может выполнить их
# одновременно и сломать порядок. Одна строка снимает вопрос целиком.
.NOTPARALLEL:

# Docker Compose v2 (плагин) на этой машине; запасной вариант — старый
# отдельный бинарник docker-compose (v1), где плагина ещё нет.
COMPOSE := $(shell docker compose version >/dev/null 2>&1 && echo "docker compose" || echo "docker-compose")

# front-* цели гоняют оба приложения по умолчанию; make front-test APP=seller
# сужает до одного. Один цикл по $(APPS) в каждой цели, а не по строке
# на приложение — третье приложение не потребует правки целей.
APPS := seller admin
APPS := $(if $(APP),$(APP),$(APPS))

# Согласовано с docker-compose.yml (postgres: POSTGRES_USER/POSTGRES_DB)
# и api/config/packages/doctrine.yaml (when@test: dbname_suffix: '_test').
DB_USER := app
DB_NAME := app
DB_TEST_NAME := $(DB_NAME)_test

.PHONY: help \
	init up down down-clear build pull ps logs \
	api-shell api-install api-migrate api-migrate-test api-console \
	db-wait db-test-create db-test-rebuild \
	test test-unit test-int test-func test-e2e test-cov \
	lint lint-fix stan deptrac structure-check audit \
	front-typecheck front-lint front-test front-knip \
	api-doc-export api-types api-types-check \
	front-install front-dev front-build \
	review-prepare review-codex review-kimi review \
	ci-local

help: ## список целей с описаниями
	@grep -hE '^[a-zA-Z0-9_-]+:.*##' $(MAKEFILE_LIST) | sort | \
		awk 'BEGIN {FS = ":.*##"}; {printf "  %-18s %s\n", $$1, $$2}'

# --- Окружение ---------------------------------------------------------

init: down-clear build up db-wait api-install front-install front-dev api-migrate ## подъём с нуля: down-clear, build, up, install, migrate
	@echo "Готово: окружение поднято, зависимости установлены, миграции применены."

up: ## запуск контейнеров
	$(COMPOSE) up -d

down: ## остановка контейнеров
	$(COMPOSE) down

down-clear: ## остановка с удалением томов
	$(COMPOSE) down -v

build: ## сборка образов
	$(COMPOSE) build

pull: ## обновление образов
	$(COMPOSE) pull

ps: ## состояние контейнеров
	$(COMPOSE) ps

logs: ## журналы контейнеров
	$(COMPOSE) logs -f

# --- Backend -------------------------------------------------------------

api-shell: ## вход в контейнер php-cli
	$(COMPOSE) exec php-cli sh

api-install: ## composer install
	$(COMPOSE) exec php-cli composer install

api-migrate: db-wait ## применение миграций (dev-база)
	$(COMPOSE) exec php-cli php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

api-migrate-test: db-wait db-test-create ## применение миграций в тестовой базе
	$(COMPOSE) exec php-cli php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env=test

api-console: ## произвольная консольная команда: make api-console CMD="..."
	$(COMPOSE) exec php-cli php bin/console $(CMD)

# --- База ------------------------------------------------------------------

db-wait: ## ожидание готовности Postgres
	@echo "Ожидание Postgres..."
	@for i in $$(seq 1 30); do \
		$(COMPOSE) exec -T postgres pg_isready -U $(DB_USER) >/dev/null 2>&1 && exit 0; \
		sleep 1; \
	done; \
	echo "Postgres не готов за 30с" >&2; exit 1

db-test-create: db-wait ## создание тестовой базы (идемпотентно)
	@$(COMPOSE) exec -T postgres psql -U $(DB_USER) -d $(DB_NAME) -tAc \
		"SELECT 1 FROM pg_database WHERE datname = '$(DB_TEST_NAME)'" | grep -q 1 || \
		$(COMPOSE) exec -T postgres psql -U $(DB_USER) -d $(DB_NAME) -c "CREATE DATABASE $(DB_TEST_NAME)"

db-test-rebuild: db-wait ## полное пересоздание тестовой базы
	$(COMPOSE) exec -T postgres psql -U $(DB_USER) -d $(DB_NAME) -c "DROP DATABASE IF EXISTS $(DB_TEST_NAME)"
	$(COMPOSE) exec -T postgres psql -U $(DB_USER) -d $(DB_NAME) -c "CREATE DATABASE $(DB_TEST_NAME)"
	$(MAKE) api-migrate-test

# --- Тесты -------------------------------------------------------------
# Подготовка тестовой базы — отдельные цели (db-test-create,
# api-migrate-test), не часть test-int/test-func: тестовую базу не нужно
# пересоздавать на каждый запуск тестов. `test` собирает полный путь для
# разработчика; в CI шаги вызываются по отдельности.

test: db-wait db-test-create api-migrate-test test-unit test-int test-func ## все уровни (unit+integration+functional); e2e — отдельно
	@echo "unit + integration + functional пройдены."

test-unit: ## тесты без БД
	$(COMPOSE) exec php-cli composer test:unit

test-int: ## тесты с БД (тестовая база должна быть готова — db-test-create, api-migrate-test)
	$(COMPOSE) exec php-cli composer test:integration

test-func: ## тесты через HTTP (тестовая база должна быть готова)
	$(COMPOSE) exec php-cli composer test:functional

test-e2e: ## Playwright, через контейнер playwright
	$(COMPOSE) exec playwright sh -c "cd /var/www/apps/seller && npx playwright test"

test-cov: ## покрытие (драйвер pcov в php-cli, docker/php/Dockerfile)
	$(COMPOSE) exec php-cli composer test:cov

# --- Проверки ------------------------------------------------------------

lint: ## PHP-CS-Fixer в режиме проверки
	$(COMPOSE) exec php-cli composer cs-check

lint-fix: ## автоисправление стиля
	$(COMPOSE) exec php-cli composer cs-fix

stan: ## PHPStan
	$(COMPOSE) exec php-cli composer stan

deptrac: ## границы модулей
	$(COMPOSE) exec php-cli composer deptrac

structure-check: ## api/src содержит только Shared/Identity/Ingestion/Kernel.php
	$(COMPOSE) exec php-cli sh bin/check-src-structure.sh

audit: ## composer audit + npm audit (оба приложения и packages/api-schema)
	$(COMPOSE) exec php-cli composer audit
	$(COMPOSE) exec node-seller npm audit
	$(COMPOSE) exec node-admin npm audit
	$(COMPOSE) exec -w /var/www/packages/api-schema node-seller npm audit

front-typecheck: ## tsc --noEmit (APP=seller|admin, по умолчанию — оба)
	@for app in $(APPS); do $(COMPOSE) exec node-$$app npm run typecheck || exit 1; done

front-lint: ## ESLint + Prettier --check (APP=seller|admin, по умолчанию — оба)
	@for app in $(APPS); do \
		$(COMPOSE) exec node-$$app npm run lint || exit 1; \
		$(COMPOSE) exec node-$$app npm run format:check || exit 1; \
	done

front-knip: ## неиспользуемый код (APP=seller|admin, по умолчанию — оба)
	@for app in $(APPS); do $(COMPOSE) exec node-$$app npm run knip || exit 1; done

front-test: ## Vitest (APP=seller|admin, по умолчанию — оба) — не в списке плана, но обязателен по CLAUDE.md (CI: Vitest)
	@for app in $(APPS); do $(COMPOSE) exec node-$$app npm run test || exit 1; done

# --- Контракт API ------------------------------------------------------
# Схема выгружается консольной командой Nelmio (не по HTTP — не нужен
# поднятый веб-сервер и авторизация) прямо в packages/api-schema/openapi.json;
# TS-типы генерируются оттуда же openapi-typescript. Оба файла — в репозитории
# (docs/structure.md, packages/api-schema).
#
# api-types / api-types-check используют exec, не run --rm (в отличие
# от front-install) — требуют уже поднятого node-seller. Как и остальные
# front-* цели, рассчитаны на то, что make init уже отработал.

# Во временный файл и mv, не напрямую в openapi.json: `>` усекает целевой
# файл до запуска команды, и упавший dump оставил бы committed-схему
# пустой/битой на диске.
api-doc-export: ## выгрузка OpenAPI в packages/api-schema/openapi.json
	$(COMPOSE) exec -T php-cli php bin/console nelmio:apidoc:dump --format=json > packages/api-schema/openapi.json.tmp
	mv packages/api-schema/openapi.json.tmp packages/api-schema/openapi.json

api-types: ## регенерация TypeScript-типов из packages/api-schema/openapi.json
	$(COMPOSE) exec -w /var/www/packages/api-schema node-seller npm run generate

# Диф в две ступени: сперва openapi.json против кода контроллеров (ловит
# правку DTO/атрибута без api-doc-export) — здесь нет готового флага
# у Nelmio, диф руками; затем schema.d.ts против openapi.json — тут
# openapi-typescript 7.13 умеет это сам через --check (ловит забытый
# api-types или ручную правку сгенерированного файла), без второго
# временного файла. rm -f перед стартом (не только trap на выходе) —
# если предыдущий прогон убили сигналом до очистки, эта проверка не должна
# молча свериться со чужим огрызком; каждый exec явно проверяется `||`,
# чтобы упавшая генерация не провалилась молча в diff по старому файлу.
api-types-check: ## закоммиченные openapi.json и schema.d.ts должны совпадать со сгенерированными из кода
	@rm -f packages/api-schema/openapi.json.check; \
	trap 'rm -f packages/api-schema/openapi.json.check' EXIT; \
	$(COMPOSE) exec -T php-cli php bin/console nelmio:apidoc:dump --format=json > packages/api-schema/openapi.json.check || \
		{ echo "nelmio:apidoc:dump упал" >&2; exit 1; }; \
	diff -u packages/api-schema/openapi.json packages/api-schema/openapi.json.check || \
		{ echo "openapi.json расходится с кодом контроллеров — make api-doc-export" >&2; exit 1; }; \
	$(COMPOSE) exec -T -w /var/www/packages/api-schema node-seller \
		npx openapi-typescript openapi.json -o src/schema.d.ts --check || \
		{ echo "schema.d.ts расходится с openapi.json — make api-types" >&2; exit 1; }

# --- Фронтенд ------------------------------------------------------------

# `run`, не `exec`: на чистом чекауте node_modules ещё нет, поэтому команда
# контейнера по умолчанию (`npm run dev`) падает и он не запускается —
# exec в такой контейнер зайти не сможет. `run --rm` поднимает одноразовый
# контейнер с переопределённой командой, не завися от состояния постоянного.
front-install: ## установка зависимостей обоих приложений и packages/api-schema (npm ci, в контейнерах)
	$(COMPOSE) run --rm node-seller npm ci
	$(COMPOSE) run --rm node-admin npm ci
	$(COMPOSE) run --rm -w /var/www/packages/api-schema node-seller npm ci

front-dev: ## запуск dev-серверов (node-seller, node-admin)
	$(COMPOSE) up -d node-seller node-admin

front-build: ## production-сборка (APP=seller|admin, по умолчанию — оба)
	@for app in $(APPS); do $(COMPOSE) exec node-$$app npm run build || exit 1; done

# --- Ревью ---------------------------------------------------------------

review-prepare: ## сборка пакета для ревью (var/review/package.md): make review-prepare TASK="..." [ADR="0006 0007"]
	sh bin/review-prepare.sh

review-codex: ## прогон Codex CLI по уже собранному var/review/package.md, ответ в var/review/codex.md
	@mkdir -p var/review
	@test -f var/review/package.md || { echo "Сначала make review-prepare TASK=\"...\"" >&2; exit 1; }
	timeout 900 codex exec --sandbox read-only -o var/review/codex.md - < var/review/package.md

review-kimi: ## прогон Kimi CLI по уже собранному var/review/package.md, ответ в var/review/kimi.md
	@mkdir -p var/review
	@test -f var/review/package.md || { echo "Сначала make review-prepare TASK=\"...\"" >&2; exit 1; }
	timeout 900 kimi -p "$$(cat var/review/package.md)" --output-format text > var/review/kimi.md

review: review-prepare ## review-prepare + оба инструмента: make review TASK="..."
	$(MAKE) review-codex
	$(MAKE) review-kimi
	@echo "Ответы: var/review/codex.md, var/review/kimi.md"

# --- Сводная ---------------------------------------------------------------
# Предполагает уже поднятое и установленное окружение (make init) —
# переустановка зависимостей на каждый локальный прогон была бы медленной
# и не нужна; в конвейере Stage 4 install — отдельный шаг перед этим же
# набором проверок.

ci-local: structure-check stan deptrac lint front-typecheck front-lint front-knip front-test audit \
	api-types-check \
	db-wait db-test-create api-migrate-test test-unit test-int test-func \
	front-build test-e2e ## всё, что прогоняет конвейер Stage 4, одной командой
	@echo "ci-local: все проверки пройдены."
