.DEFAULT_GOAL := help
DEV_ENVIRONMENT := php scripts/dev-environment.php
TOOLING_EXEC := php "$(dir $(abspath $(lastword $(MAKEFILE_LIST))))scripts/exec-command.php"
DEV_TARGET = $(if $(INSTANCE),--instance $(INSTANCE),)
DEV_MEDIA = $(if $(MEDIA),--media "$(MEDIA)",)
STATIC_CHECK := php scripts/check-backend-container.php
STATIC_IMAGE = $(if $(BAANDER_TEST_IMAGE),--image "$(BAANDER_TEST_IMAGE)",)
override cmd := $(value cmd)
export cmd
INSIDE_DOCKER_CONTAINER ?= 0
ERROR_ONLY_FOR_HOST = @echo "Run this command on the host."

help: ## List available commands
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "%-25s %s\n", $$1, $$2}' Makefile

dev-host-setup: ## Write one-time DNS/trust instructions; OPTIONS=--install-trust installs certificates
	@$(DEV_ENVIRONMENT) host-setup $(OPTIONS)

dev-up: ## Start or resume baander.test and enable automatic master updates
	@$(DEV_ENVIRONMENT) up --instance persistent $(DEV_MEDIA) $(OPTIONS)

dev-down: ## Disable automatic updates and stop baander.test, retaining data
	@$(DEV_ENVIRONMENT) down --instance persistent

dev-status: ## Show persistent instance revision, readiness and update state
	@$(DEV_ENVIRONMENT) status --instance persistent $(OPTIONS)

dev-update: ## Check remote master now; OPTIONS=--retry retries a failed build
	@$(DEV_ENVIRONMENT) update --instance persistent $(OPTIONS)

dev-recover: ## Reconcile a blocked attempt after database history has been checked
	@$(DEV_ENVIRONMENT) recover --instance persistent

preview-up: ## Start this worktree's preview and refresh its worker snapshot
	@$(DEV_ENVIRONMENT) up $(DEV_MEDIA) $(OPTIONS)

preview-down: ## Stop this worktree's preview, retaining its data
	@$(DEV_ENVIRONMENT) down

preview-status: ## Show this worktree's preview and worker snapshot
	@$(DEV_ENVIRONMENT) status $(OPTIONS)

preview-url: ## Print the ready isolated URL for browser verification
	@$(DEV_ENVIRONMENT) url

build build-dev build-clean build-all start: preview-up
stop down: preview-down
restart restart-app: ## Refresh this preview; INSTANCE=persistent targets baander.test explicitly
	@$(DEV_ENVIRONMENT) restart $(DEV_TARGET)

ssh: ## Open a shell in this preview (INSTANCE=persistent selects baander.test)
	@$(DEV_ENVIRONMENT) exec $(DEV_TARGET) -- bash

ssh-root: ## Open a root shell in the selected instance
	@$(DEV_ENVIRONMENT) exec $(DEV_TARGET) --user root -- bash

ssh-nginx: ## Open a shell in the selected instance frontend
	@$(DEV_ENVIRONMENT) exec $(DEV_TARGET) --service nginx -- /bin/sh

exec: ## Run cmd in this preview; INSTANCE=persistent is required for baander.test
ifeq ($(INSIDE_DOCKER_CONTAINER), 1)
	@$(TOOLING_EXEC)
else
	@$(DEV_ENVIRONMENT) exec $(DEV_TARGET) --command-env cmd
endif

exec-bash:
ifeq ($(INSIDE_DOCKER_CONTAINER), 1)
	@bash -c "$$cmd"
else
	@$(DEV_ENVIRONMENT) exec $(DEV_TARGET) --shell-command-env cmd
endif

exec-by-root:
	@$(DEV_ENVIRONMENT) exec $(DEV_TARGET) --user root --command-env cmd

logs: ## Follow logs for the selected instance
	@$(DEV_ENVIRONMENT) logs $(DEV_TARGET) --follow

logs-nginx: ## Follow frontend logs for the selected instance
	@$(DEV_ENVIRONMENT) logs $(DEV_TARGET) --service nginx --follow

migrate migrate-dev migrate-no-test: ## Migrate the selected preview database
	@$(MAKE) exec cmd="php bin/console doctrine:migrations:migrate --no-interaction"

composer-install:
	@$(MAKE) exec cmd="composer install --optimize-autoloader"

composer-install-no-dev:
	@$(MAKE) exec cmd="composer install --optimize-autoloader --no-dev"

composer-update:
	@$(MAKE) exec cmd="composer update"

info:
	@$(MAKE) exec cmd="php bin/console about"

config-check:
	@$(MAKE) exec cmd="php bin/console app:config:validate"

phpunit: ## Runs isolated backend tests with coverage
	@bash scripts/test-backend-container.sh $(PHPUNIT_OPTIONS) --coverage-text=coverage/report.txt --coverage-clover=coverage/clover.xml

paratest: ## Runs isolated backend tests with ParaTest
	@bash scripts/test-backend-container.sh $(PARATEST_OPTIONS)

phpstan: ## Runs PHPStan static analysis
	@$(STATIC_CHECK) $(STATIC_IMAGE) -- php ./vendor/bin/phpstan analyse --memory-limit=1G $(PHPSTAN_OPTIONS)

phpstan-baseline: ## Generates PHPStan baseline for existing errors
	@make exec-bash cmd="XDEBUG_MODE=off php ./vendor/bin/phpstan analyse --memory-limit=1G --generate-baseline phpstan-baseline.neon"

composer-normalize: ## Normalizes composer.json file content
	@make exec cmd="composer normalize"

composer-validate: ## Validates composer.json file content
	@make exec cmd="composer validate --no-check-version"

composer-require-checker: ## Checks the defined dependencies against your code
	@make exec-bash cmd="XDEBUG_MODE=off php ./vendor/bin/composer-require-checker"

composer-unused: ## Shows unused packages by scanning and comparing package namespaces against your code
	@make exec-bash cmd="XDEBUG_MODE=off php ./vendor/bin/composer-unused"

source-docs:
	./phpDocumentor.phar

docs: ## Generate documentation site from source code and docs-book/
	@make exec-bash cmd="php bin/console app:generate-docs"

ci: ## Run static and backend checks in disposable CI containers
	@$(STATIC_CHECK) $(STATIC_IMAGE) -- composer validate --no-check-version
	@$(STATIC_CHECK) $(STATIC_IMAGE) -- composer normalize --dry-run
	@$(MAKE) phpstan
	@$(STATIC_CHECK) $(STATIC_IMAGE) -- vendor/bin/deptrac analyse --no-cache --no-progress
	@$(MAKE) phpunit

# ── Profiling (reli-prof) ────────────────────────────────────────────────────

prof-up: ## Start the reli-profiler sidecar container
ifeq ($(INSIDE_DOCKER_CONTAINER), 0)
	@bin/reli up
else
	$(ERROR_ONLY_FOR_HOST)
endif

prof-down: ## Stop the reli-profiler sidecar container
ifeq ($(INSIDE_DOCKER_CONTAINER), 0)
	@bin/reli down
else
	$(ERROR_ONLY_FOR_HOST)
endif

prof-top: ## Show live profiler top view (ctrl+c to stop)
ifeq ($(INSIDE_DOCKER_CONTAINER), 0)
	@bin/reli top $(PROF_ARGS)
else
	$(ERROR_ONLY_FOR_HOST)
endif

prof-trace: ## Capture a trace. Usage: make prof-trace PID=1234
ifeq ($(INSIDE_DOCKER_CONTAINER), 0)
	@bin/reli trace -p $(PID) $(PROF_ARGS)
else
	$(ERROR_ONLY_FOR_HOST)
endif

prof-memory: ## Take a memory snapshot. Usage: make prof-memory PID=1234
ifeq ($(INSIDE_DOCKER_CONTAINER), 0)
	@bin/reli memory -p $(PID) $(PROF_ARGS)
else
	$(ERROR_ONLY_FOR_HOST)
endif

prof-flamegraph: ## Generate a flamegraph. Usage: make prof-flamegraph PID=1234
ifeq ($(INSIDE_DOCKER_CONTAINER), 0)
	@bin/reli flamegraph -p $(PID) $(PROF_ARGS)
else
	$(ERROR_ONLY_FOR_HOST)
endif

.PHONY: help dev-host-setup dev-up dev-down dev-status dev-update dev-recover preview-up preview-down preview-status preview-url start stop down restart restart-app exec exec-bash exec-by-root ssh ssh-root ssh-nginx logs logs-nginx migrate migrate-dev migrate-no-test phpunit phpstan ci
