DC = docker compose
NO_TTY = $(if $(CI),-T)
EXEC = $(DC) exec $(NO_TTY)
RUN = $(DC) run $(NO_TTY) --rm
PHP = $(EXEC) php
CONSOLE = $(PHP) php bin/console

IMAGE ?= docker.io/injust/mossytrunk
TAG ?= $(shell git rev-parse --short=7 HEAD)
PLATFORM ?= linux/amd64
DEPLOY_HOST ?= debian@duprat.cloud
DEPLOY_DIR ?= /mnt/mossytrunk
REMOTE_DOCKER ?= sudo -n docker
E2E_ASSETS_DIR ?= build-e2e
E2E_LANES ?= 4
POSTGRES_USER ?= app
PLAYWRIGHT_ARGS ?=
CI_BUNDLE ?= ci-build.tgz
CRAP_REPORT = var/coverage/crap4j.xml
export E2E_ASSETS_DIR
OUTPUT_SYNC = $(if $(filter output-sync,$(.FEATURES)),--output-sync=target)
BUILD = docker buildx build --platform $(PLATFORM) --target prod -t $(IMAGE):$(TAG) -t $(IMAGE):latest

.PHONY: up down build install assets assets-e2e db db-test fixtures migration test crap test-unit test-functional deptrac phpstan cs cs-fix e2e e2e-run qa ci ci-up ci-build ci-bundle ci-unbundle ci-warmup ci-checks ci-e2e image push deploy deploy-files

up: ## Start the stack (app on http://localhost:8080, mock mossyleaf accounts on :8092)
	$(DC) up -d --wait php database node oidc

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
	$(RUN) --no-deps -e ASSETS_DIR=build-e2e node npm run build

db: ## Create and migrate the dev database
	$(CONSOLE) doctrine:database:create --if-not-exists
	$(CONSOLE) doctrine:migrations:migrate --no-interaction --allow-no-migration

fixtures: db ## Reset the dev database with mock data (Foundry story)
	$(CONSOLE) doctrine:fixtures:load --no-interaction --purge-with-truncate

db-test: ## Create and migrate the test database
	$(CONSOLE) doctrine:database:create --if-not-exists --env=test
	$(CONSOLE) doctrine:migrations:migrate --no-interaction --allow-no-migration --env=test

migration: ## Generate a migration from mapping changes
	$(CONSOLE) doctrine:migrations:diff --no-interaction

test: db-test ## PHPUnit (unit + functional)
	$(PHP) php bin/phpunit $(PHPUNIT_COVERAGE)

crap: PHPUNIT_COVERAGE = --coverage-crap4j $(CRAP_REPORT)
crap: test ## PHPUnit with coverage, fails when a src method has a CRAP score above 10
	$(PHP) php bin/crap $(CRAP_REPORT)

test-unit:
	$(PHP) php bin/phpunit --testsuite unit

test-functional: db-test
	$(PHP) php bin/phpunit --testsuite functional

deptrac: ## Check onion layer dependencies
	$(PHP) vendor/bin/deptrac analyse --no-progress

cs: ## Coding standard check (PHP-CS-Fixer, @Symfony + strict types)
	$(EXEC) -e PHP_CS_FIXER_IGNORE_ENV=1 php vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix: ## Apply the coding standard
	$(EXEC) -e PHP_CS_FIXER_IGNORE_ENV=1 php vendor/bin/php-cs-fixer fix

phpstan: ## Static analysis (level in phpstan.dist.neon)
	$(PHP) vendor/bin/phpstan analyse --no-progress --memory-limit=1G

e2e: assets-e2e e2e-run ## Playwright end-to-end tests against a dedicated app container

e2e-run: ## Playwright against already built assets (E2E_ASSETS_DIR, default build-e2e), E2E_LANES tests at a time (1-4)
	$(DC) --profile e2e up -d --wait php-e2e oidc
	$(EXEC) php-e2e php bin/console cache:clear --env=test
	$(EXEC) php-e2e curl -sf -X POST http://localhost:2019/frankenphp/workers/restart
	$(EXEC) -e TEST_TOKEN=1 php-e2e php bin/console doctrine:database:drop --force --if-exists --env=test
	$(EXEC) -e TEST_TOKEN=1 php-e2e php bin/console doctrine:database:create --env=test
	$(EXEC) -e TEST_TOKEN=1 php-e2e php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env=test
	lane=2; while [ $$lane -le $(E2E_LANES) ]; do \
		$(EXEC) database psql -U $(POSTGRES_USER) -d postgres -q -c "DROP DATABASE IF EXISTS app_e2e_test$$lane" -c "CREATE DATABASE app_e2e_test$$lane TEMPLATE app_e2e_test1" || exit 1; \
		lane=$$((lane + 1)); \
	done
	$(DC) --profile e2e run $(NO_TTY) --rm -e E2E_LANES=$(E2E_LANES) playwright sh -c "npm ci --no-audit --no-fund && ./node_modules/.bin/playwright test $(PLAYWRIGHT_ARGS)"

qa: cs phpstan deptrac crap e2e

ci: ci-build ## Full suite from a fresh checkout (GitHub Actions builds once in ci-build, then runs ci-checks and ci-e2e shards in parallel jobs)
	$(MAKE) -j4 $(OUTPUT_SYNC) ci-checks
	$(MAKE) ci-e2e

ci-up:
	$(DC) up -d --wait php database oidc

ci-build: ci-up ## Install PHP and JS dependencies and build the production assets
	$(PHP) composer install --no-interaction --no-progress
	$(RUN) --no-deps node npm ci --no-audit --no-fund
	$(MAKE) assets

ci-bundle: ## Pack what ci-build produced for the CI test jobs (CI_BUNDLE)
	tar -czf $(CI_BUNDLE) vendor public/build $(wildcard public/bundles)

ci-unbundle: ## Unpack the ci-build output (CI_BUNDLE)
	tar -xzf $(CI_BUNDLE)

ci-warmup: ci-up ## Start the dev stack and warm its cache (PHPStan reads the dev container)
	$(CONSOLE) cache:warmup

ci-e2e: ## Playwright on the production build (PLAYWRIGHT_ARGS to pick projects or a shard)
	$(MAKE) e2e-run E2E_ASSETS_DIR=build

ci-checks: cs phpstan deptrac crap

image: ## Build the production image locally (IMAGE, TAG, PLATFORM)
	$(BUILD) --load .

push: qa ## Run the full suite, then build and push the production image by hand (CI does it on every push to main; run docker login first)
	$(BUILD) --push .

deploy-files: ## Copy deploy/ (compose, env template, README) to DEPLOY_HOST:DEPLOY_DIR
	@test -n "$(DEPLOY_HOST)" || { echo "Set DEPLOY_HOST=user@server"; exit 1; }
	ssh $(DEPLOY_HOST) 'mkdir -p $(DEPLOY_DIR)'
	scp deploy/compose.yaml deploy/.env.dist deploy/README.md $(DEPLOY_HOST):$(DEPLOY_DIR)/

deploy: ## Run IMAGE:TAG (published by CI) on DEPLOY_HOST: set TAG in its .env, pull, restart
	@curl -sf -o /dev/null https://hub.docker.com/v2/repositories/$(patsubst docker.io/%,%,$(IMAGE))/tags/$(TAG) || { echo "$(IMAGE):$(TAG) is not published: CI only publishes it once the full test suite passes (still running, or failed?)"; exit 1; }
	scp deploy/compose.yaml $(DEPLOY_HOST):$(DEPLOY_DIR)/
	ssh $(DEPLOY_HOST) 'set -e; cd $(DEPLOY_DIR); \
		sed -i "s|^IMAGE=.*|IMAGE=$(IMAGE)|; s|^TAG=.*|TAG=$(TAG)|" .env; \
		$(REMOTE_DOCKER) compose pull app; \
		$(REMOTE_DOCKER) compose up -d --remove-orphans'
