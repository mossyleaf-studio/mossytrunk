# Shared Symfony tooling of the Mossyleaf apps.
# Synced from ~/Sites/mossyleaf-studio/toolchain: edit it there, then run its ./sync.
# The project Makefile sets IMAGE, DEPLOY_HOST, DEPLOY_DIR and any hook below, then includes this file.

DC = docker compose
NO_TTY = $(if $(CI),-T)
EXEC = $(DC) exec $(NO_TTY)
RUN = $(DC) run $(NO_TTY) --rm
PHP = $(EXEC) php
CONSOLE = $(PHP) php bin/console

TAG ?= $(shell git rev-parse --short=7 HEAD)
PLATFORM ?= linux/amd64
REMOTE_DOCKER ?= sudo -n docker
# Local deploy target (DEPLOY_HOST, DEPLOY_DIR, REMOTE_DOCKER), git-ignored.
-include .deploy.env
DEPLOY_FILES ?= deploy/compose.yaml deploy/.env.dist deploy/README.md

# Hooks: services each stack starts, and what the gate runs.
UP_SERVICES ?= php database node oidc
CI_SERVICES ?= php database oidc
CHECKS ?= cs phpstan deptrac audit crap test-js
PHPUNIT = php bin/phpunit
PHPUNIT_ARGS ?=
# crap: every src method keeps a CRAP score at or below CRAP_MAX (PHPUnit coverage needs pcov in the dev image).
# pcov ships disabled in the dev image (enabled, it slows all PHP 2x) and crap turns it on for its run.
# It skips #[Group('timing')] tests: coverage slows code 2-4x, so wall-clock budgets run without it (make test).
CRAP_MAX ?= 10
CRAP_REPORT = var/coverage/crap4j.xml

# e2e: E2E_ENV adds `docker compose exec` flags to its console commands, E2E_RESET runs before the database
# is rebuilt, E2E_SEED after it, E2E_LANES > 1 copies the e2e database for parallel Playwright workers.
E2E_ASSETS_DIR ?= build-e2e
E2E_SERVICES ?= php-e2e
E2E_ENV ?=
E2E_RESET ?=
E2E_CONSOLE = $(EXEC) $(E2E_ENV) php-e2e php bin/console
E2E_SEED ?= $(E2E_CONSOLE) doctrine:fixtures:load --no-interaction --env=test
E2E_LANES ?= 1
POSTGRES_USER ?= app
PLAYWRIGHT_ARGS ?=
export E2E_ASSETS_DIR

OUTPUT_SYNC = $(if $(filter output-sync,$(.FEATURES)),--output-sync=target)
BUILD = docker buildx build --platform $(PLATFORM) --target prod -t $(IMAGE):$(TAG) -t $(IMAGE):latest
NEEDS_DEPLOY_TARGET = @test -n "$(DEPLOY_HOST)" -a -n "$(DEPLOY_DIR)" || { echo "Set DEPLOY_HOST and DEPLOY_DIR (e.g. in .deploy.env)"; exit 1; }

.PHONY: up down build install assets assets-e2e db db-test fixtures migration test crap test-unit test-functional test-js deptrac phpstan cs cs-fix audit audit-php audit-js e2e e2e-run qa ci ci-up ci-build ci-warmup ci-checks ci-e2e image push deploy deploy-files

up: ## Start the dev stack (URLs at the top of the Makefile)
	$(DC) up -d --wait $(UP_SERVICES)

down:
	$(DC) down

build:
	$(DC) build

install:
	$(PHP) composer install
	$(RUN) --no-deps node npm install

assets:
	$(RUN) --no-deps node npm run build

assets-e2e:
	$(RUN) --no-deps -e ASSETS_DIR=$(E2E_ASSETS_DIR) node npm run build

db: ## Create and migrate the dev database
	$(CONSOLE) doctrine:database:create --if-not-exists
	$(CONSOLE) doctrine:migrations:migrate --no-interaction --allow-no-migration

fixtures: db ## Reset the dev database with demo data
	$(CONSOLE) doctrine:fixtures:load --no-interaction --purge-with-truncate

db-test: ## Create and migrate the test database
	$(CONSOLE) doctrine:database:create --if-not-exists --env=test
	$(CONSOLE) doctrine:migrations:migrate --no-interaction --allow-no-migration --env=test

migration: ## Generate a migration from mapping changes
	$(CONSOLE) doctrine:migrations:diff --no-interaction

test: db-test ## PHPUnit (unit + functional)
	$(PHP) $(PHPUNIT) $(PHPUNIT_ARGS)

crap: PHPUNIT = php -d pcov.enabled=1 bin/phpunit
crap: PHPUNIT_ARGS = --coverage-crap4j $(CRAP_REPORT) --exclude-group timing
crap: test ## PHPUnit with coverage, fails when a src method has a CRAP score above CRAP_MAX
	$(PHP) php bin/crap $(CRAP_REPORT) $(CRAP_MAX)

test-unit:
	$(PHP) php bin/phpunit --testsuite unit

test-functional: db-test
	$(PHP) php bin/phpunit --testsuite functional

test-js: ## Vitest (pure JS modules)
	$(RUN) --no-deps node npx vitest run

deptrac: ## Check onion layer dependencies
	$(PHP) vendor/bin/deptrac analyse --no-progress

cs: ## Coding standard check
	$(EXEC) -e PHP_CS_FIXER_IGNORE_ENV=1 php vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix: ## Apply the coding standard
	$(EXEC) -e PHP_CS_FIXER_IGNORE_ENV=1 php vendor/bin/php-cs-fixer fix

phpstan: ## Static analysis (level in phpstan.dist.neon)
	$(PHP) vendor/bin/phpstan analyse --no-progress --memory-limit=1G

audit: audit-php audit-js ## Known vulnerabilities in the locked dependencies

audit-php:
	$(PHP) composer audit

audit-js:
	$(RUN) --no-deps node npm audit --omit=dev --audit-level=high

e2e: assets-e2e ## Playwright against a dedicated APP_ENV=test container
	$(MAKE) e2e-run

e2e-run: ## Playwright against already built assets (E2E_ASSETS_DIR, default build-e2e)
	$(DC) --profile e2e up -d --wait $(E2E_SERVICES)
	$(EXEC) php-e2e php bin/console cache:clear --env=test
	$(E2E_RESET)
	$(E2E_CONSOLE) doctrine:database:drop --force --if-exists --env=test
	$(E2E_CONSOLE) doctrine:database:create --env=test
	$(E2E_CONSOLE) doctrine:migrations:migrate --no-interaction --allow-no-migration --env=test
	$(E2E_SEED)
	lane=2; while [ $$lane -le $(E2E_LANES) ]; do \
		$(EXEC) database psql -U $(POSTGRES_USER) -d postgres -q -c "DROP DATABASE IF EXISTS app_e2e_test$$lane" -c "CREATE DATABASE app_e2e_test$$lane TEMPLATE app_e2e_test1" || exit 1; \
		lane=$$((lane + 1)); \
	done
	$(DC) --profile e2e run $(NO_TTY) --rm -e E2E_LANES=$(E2E_LANES) playwright sh -c "npm ci --no-audit --no-fund && ./node_modules/.bin/playwright test $(PLAYWRIGHT_ARGS)"

qa: ## Full local gate: the checks and e2e at the same time (their databases differ)
	$(MAKE) -j6 $(OUTPUT_SYNC) ci-checks e2e

ci: ci-build ## Full suite from a fresh checkout (GitHub Actions runs ci-build, then ci-checks or a ci-e2e shard, in each job)
	$(MAKE) -j4 $(OUTPUT_SYNC) ci-checks
	$(MAKE) ci-e2e

ci-up:
	$(DC) up -d --wait $(CI_SERVICES)

ci-build: ci-up ## Install PHP and JS dependencies (node_modules kept when the CI cache restored it) and build the production assets
	$(PHP) composer install --no-interaction --no-progress
	test -d node_modules || $(RUN) --no-deps node npm ci --no-audit --no-fund
	$(MAKE) assets

ci-warmup: ci-up ## Start the dev stack and warm its cache (PHPStan reads the dev container)
	$(CONSOLE) cache:warmup

ci-e2e: ## Playwright on the production build (PLAYWRIGHT_ARGS to pick projects or a shard)
	$(MAKE) e2e-run E2E_ASSETS_DIR=build

ci-checks: $(CHECKS)

image: ## Build the production image locally (IMAGE, TAG, PLATFORM)
	$(BUILD) --load .

push: qa ## Run the full suite, then build and push the production image by hand (CI does it on every push to main; run docker login first)
	$(BUILD) --push .

deploy-files: ## Copy DEPLOY_FILES (compose, env template, README) to DEPLOY_HOST:DEPLOY_DIR
	$(NEEDS_DEPLOY_TARGET)
	ssh $(DEPLOY_HOST) 'mkdir -p $(DEPLOY_DIR)'
	scp $(DEPLOY_FILES) $(DEPLOY_HOST):$(DEPLOY_DIR)/

deploy: ## Run IMAGE:TAG (published by CI) on DEPLOY_HOST: set TAG in its .env, pull, restart
	$(NEEDS_DEPLOY_TARGET)
	@curl -sf -o /dev/null https://hub.docker.com/v2/repositories/$(patsubst docker.io/%,%,$(IMAGE))/tags/$(TAG) || { echo "$(IMAGE):$(TAG) is not published: CI only publishes it once the full test suite passes (still running, or failed?)"; exit 1; }
	scp deploy/compose.yaml $(DEPLOY_HOST):$(DEPLOY_DIR)/
	ssh $(DEPLOY_HOST) 'set -e; cd $(DEPLOY_DIR); \
		sed -i "s|^IMAGE=.*|IMAGE=$(IMAGE)|; s|^TAG=.*|TAG=$(TAG)|" .env; \
		$(REMOTE_DOCKER) compose pull app; \
		$(REMOTE_DOCKER) compose up -d --remove-orphans'

print-%: ## Print a make variable (CI reads IMAGE with `make -s print-IMAGE`)
	@echo '$($*)'
