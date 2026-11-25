# Dev stack: app on http://localhost:8080, mock mossyleaf accounts on :8092.
# Shared targets live in common.mk (synced from ~/Sites/mossyleaf-studio/toolchain).

IMAGE ?= docker.io/injust/mossytrunk
DEPLOY_HOST ?= debian@duprat.cloud
DEPLOY_DIR ?= /mnt/mossytrunk
# No Vitest here.
CHECKS = cs phpstan deptrac audit crap
# e2e: FrankenPHP workers, Foundry stories seeded by the tests, one database per Playwright lane (TEST_TOKEN suffix).
E2E_SERVICES = php-e2e oidc
E2E_ENV = -e TEST_TOKEN=1
E2E_RESET = $(EXEC) php-e2e curl -sf -X POST http://localhost:2019/frankenphp/workers/restart
E2E_SEED =
E2E_LANES ?= 4

include common.mk
