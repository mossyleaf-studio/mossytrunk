# Dev stack: app on http://localhost:8080, mock mossyleaf accounts on :8092.
# Shared targets live in common.mk (synced from ~/Sites/mossyleaf-studio/toolchain).

IMAGE ?= docker.io/injust/mossytrunk
DEPLOY_HOST ?= debian@duprat.cloud
DEPLOY_DIR ?= /mnt/mossytrunk
# No Vitest here; PHPUnit runs with coverage for the CRAP check.
CHECKS = cs phpstan deptrac audit crap
CRAP_REPORT = var/coverage/crap4j.xml
# e2e: FrankenPHP workers, Foundry stories seeded by the tests, one database per Playwright lane (TEST_TOKEN suffix).
E2E_SERVICES = php-e2e oidc
E2E_ENV = -e TEST_TOKEN=1
E2E_RESET = $(EXEC) php-e2e curl -sf -X POST http://localhost:2019/frankenphp/workers/restart
E2E_SEED =
E2E_LANES ?= 4

include common.mk

.PHONY: crap

crap: PHPUNIT_ARGS = --coverage-crap4j $(CRAP_REPORT)
crap: test ## PHPUnit with coverage, fails when a src method has a CRAP score above 10
	$(PHP) php bin/crap $(CRAP_REPORT)
