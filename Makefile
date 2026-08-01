export WEB_PORT_HTTP=80
export WEB_PORT_SSL=443
export XDEBUG_CONFIG=main
export XDEBUG_VERSION=3.5.0

# Determine if .env file exist
ifneq ("$(wildcard .env)","")
	include .env
endif

HOST_UID := $(shell id -u)
HOST_GID := $(shell id -g)
PHP_USER := -u www-data
INTERACTIVE := $(shell [ -t 0 ] && echo 1)
ERROR_ONLY_FOR_HOST = @printf "\033[33mThis command for host machine\033[39m\n"

ifndef INSIDE_DOCKER_CONTAINER
	INSIDE_DOCKER_CONTAINER = 0
endif

help: ## Shows available commands with description
	@echo "\033[34mList of available commands:\033[39m"
	@grep -E '^[a-zA-Z-]+:.*?## .*$$' Makefile | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "[32m%-27s[0m %s\n", $$1, $$2}'

COMPOSE_BUILD_ARGS = HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) WEB_PORT_HTTP=$(WEB_PORT_HTTP) WEB_PORT_SSL=$(WEB_PORT_SSL) XDEBUG_CONFIG=$(XDEBUG_CONFIG) XDEBUG_VERSION=$(XDEBUG_VERSION) GITHUB_TOKEN=$(GITHUB_TOKEN) REDIS_PASSWORD=$(REDIS_PASSWORD)

build: build-dev ## Build dev environment (alias)

build-dev: ## Build dev environment
ifeq ($(INSIDE_DOCKER_CONTAINER), 0)
	@$(COMPOSE_BUILD_ARGS) docker compose --progress=plain -f docker-compose.yml build
else
	$(ERROR_ONLY_FOR_HOST)
endif

build-prod: ## Build production image
ifeq ($(INSIDE_DOCKER_CONTAINER), 0)
	@$(COMPOSE_BUILD_ARGS) docker compose --progress=plain -f docker-compose.yml build --target production
else
	$(ERROR_ONLY_FOR_HOST)
endif

build-clean: ## Build dev environment without cache
ifeq ($(INSIDE_DOCKER_CONTAINER), 0)
	@$(COMPOSE_BUILD_ARGS) docker compose --progress=plain -f docker-compose.yml build --no-cache
else
	$(ERROR_ONLY_FOR_HOST)
endif

# build-ffmpeg: disabled — using apt ffmpeg for now
# cd docker/ffmpeg && docker build --progress=plain --tag martinjuul/ffmpeg-baander-static:latest .

build-all: build-clean ## Build full dev environment

start: ## Start dev environment
ifeq ($(INSIDE_DOCKER_CONTAINER), 0)
	@HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) WEB_PORT_HTTP=$(WEB_PORT_HTTP) WEB_PORT_SSL=$(WEB_PORT_SSL) XDEBUG_CONFIG=$(XDEBUG_CONFIG) XDEBUG_VERSION=$(XDEBUG_VERSION) REDIS_PASSWORD=$(REDIS_PASSWORD) docker compose -f docker-compose.yml up -d
else
	$(ERROR_ONLY_FOR_HOST)
endif

stop: ## Stop dev environment containers
ifeq ($(INSIDE_DOCKER_CONTAINER), 0)
	@HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) WEB_PORT_HTTP=$(WEB_PORT_HTTP) WEB_PORT_SSL=$(WEB_PORT_SSL) XDEBUG_CONFIG=$(XDEBUG_CONFIG) XDEBUG_VERSION=$(XDEBUG_VERSION) docker compose -f docker-compose.yml stop
else
	$(ERROR_ONLY_FOR_HOST)
endif

down: ## Stop and remove dev environment containers, networks
ifeq ($(INSIDE_DOCKER_CONTAINER), 0)
	@HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) WEB_PORT_HTTP=$(WEB_PORT_HTTP) WEB_PORT_SSL=$(WEB_PORT_SSL) XDEBUG_CONFIG=$(XDEBUG_CONFIG) XDEBUG_VERSION=$(XDEBUG_VERSION) REDIS_PASSWORD=$(REDIS_PASSWORD) docker compose -f docker-compose.yml down
else
	$(ERROR_ONLY_FOR_HOST)
endif

restart: stop start ## Stop and start dev environment

env-dev: ## Creates config for dev environment
	@make exec cmd="cp ./.env.example ./.env"

restart-app: ## Restart app container
ifeq ($(INSIDE_DOCKER_CONTAINER), 0)
	@HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) WEB_PORT_HTTP=$(WEB_PORT_HTTP) WEB_PORT_SSL=$(WEB_PORT_SSL) XDEBUG_CONFIG=$(XDEBUG_CONFIG) XDEBUG_VERSION=$(XDEBUG_VERSION) REDIS_PASSWORD=$(REDIS_PASSWORD) docker compose restart app
else
	$(ERROR_ONLY_FOR_HOST)
endif

ssh: ## Get bash inside app container
ifeq ($(INSIDE_DOCKER_CONTAINER), 0)
	@HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) WEB_PORT_HTTP=$(WEB_PORT_HTTP) WEB_PORT_SSL=$(WEB_PORT_SSL) XDEBUG_CONFIG=$(XDEBUG_CONFIG) XDEBUG_VERSION=$(XDEBUG_VERSION) REDIS_PASSWORD=$(REDIS_PASSWORD) docker compose exec $(OPTION_T) $(PHP_USER) app bash
else
	$(ERROR_ONLY_FOR_HOST)
endif

ssh-root: ## Get bash as root user inside app container
ifeq ($(INSIDE_DOCKER_CONTAINER), 0)
	@HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) WEB_PORT_HTTP=$(WEB_PORT_HTTP) WEB_PORT_SSL=$(WEB_PORT_SSL) XDEBUG_CONFIG=$(XDEBUG_CONFIG) XDEBUG_VERSION=$(XDEBUG_VERSION) REDIS_PASSWORD=$(REDIS_PASSWORD) docker compose  exec $(OPTION_T) app bash
else
	$(ERROR_ONLY_FOR_HOST)
endif

ssh-nginx: ## Get bash inside nginx docker container
ifeq ($(INSIDE_DOCKER_CONTAINER), 0)
	@HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) WEB_PORT_HTTP=$(WEB_PORT_HTTP) WEB_PORT_SSL=$(WEB_PORT_SSL) XDEBUG_CONFIG=$(XDEBUG_CONFIG) XDEBUG_VERSION=$(XDEBUG_VERSION) REDIS_PASSWORD=$(REDIS_PASSWORD) docker compose  exec nginx /bin/sh
else
	$(ERROR_ONLY_FOR_HOST)
endif

exec:
ifeq ($(INSIDE_DOCKER_CONTAINER), 1)
	@$$cmd
else
	@HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) WEB_PORT_HTTP=$(WEB_PORT_HTTP) WEB_PORT_SSL=$(WEB_PORT_SSL) XDEBUG_CONFIG=$(XDEBUG_CONFIG) XDEBUG_VERSION=$(XDEBUG_VERSION) REDIS_PASSWORD=$(REDIS_PASSWORD) docker compose exec $(OPTION_T) $(PHP_USER) app $$cmd
endif

exec-bash:
ifeq ($(INSIDE_DOCKER_CONTAINER), 1)
	@bash -c "$(cmd)"
else
	@HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) WEB_PORT_HTTP=$(WEB_PORT_HTTP) WEB_PORT_SSL=$(WEB_PORT_SSL) XDEBUG_CONFIG=$(XDEBUG_CONFIG) XDEBUG_VERSION=$(XDEBUG_VERSION) REDIS_PASSWORD=$(REDIS_PASSWORD) docker compose  exec $(OPTION_T) $(PHP_USER) app bash -c "$(cmd)"
endif

exec-by-root:
ifeq ($(INSIDE_DOCKER_CONTAINER), 0)
	@HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) WEB_PORT_HTTP=$(WEB_PORT_HTTP) WEB_PORT_SSL=$(WEB_PORT_SSL) XDEBUG_CONFIG=$(XDEBUG_CONFIG) XDEBUG_VERSION=$(XDEBUG_VERSION) REDIS_PASSWORD=$(REDIS_PASSWORD) docker compose  exec $(OPTION_T) app $$cmd
else
	$(ERROR_ONLY_FOR_HOST)
endif

composer-install-no-dev: ## Installs composer no-dev dependencies
	@make exec-bash cmd="COMPOSER_MEMORY_LIMIT=-1 composer install --optimize-autoloader --no-dev"

composer-install: ## Installs composer dependencies
	@make exec-bash cmd="COMPOSER_MEMORY_LIMIT=-1 composer install --optimize-autoloader"

composer-update: ## Updates composer dependencies
	@make exec-bash cmd="COMPOSER_MEMORY_LIMIT=-1 composer update"

key-generate: ## (Legacy) APP_SECRET is managed in .env — no console command needed
	@echo "APP_SECRET is configured in .env. No generate command needed for Symfony."

info: ## Shows PHP and Symfony version
	@make exec cmd="php --version"
	@make exec cmd="php bin/console about"
	@make exec cmd="composer --version"

config-check: ## Validate application configuration
	@make exec cmd="php bin/console app:config:validate"

logs: ## Shows logs from the app container. Use ctrl+c in order to exit
ifeq ($(INSIDE_DOCKER_CONTAINER), 0)
	@docker logs -f baander-app
else
	$(ERROR_ONLY_FOR_HOST)
endif

logs-nginx: ## Shows logs from the nginx container. Use ctrl+c in order to exit
ifeq ($(INSIDE_DOCKER_CONTAINER), 0)
	@docker logs -f baander-nginx
else
	$(ERROR_ONLY_FOR_HOST)
endif

HL := ./hl

hl-logs: ## Show app container logs piped through hl (highlighted JSON/logfmt)
ifeq ($(INSIDE_DOCKER_CONTAINER), 0)
	@docker logs --since 1h baander-app 2>&1 | $(HL) -s -L
else
	$(ERROR_ONLY_FOR_HOST)
endif

hl-logs-f: ## Follow app container logs piped through hl
ifeq ($(INSIDE_DOCKER_CONTAINER), 0)
	@docker logs -f baander-app 2>&1 | $(HL) -F -L
else
	$(ERROR_ONLY_FOR_HOST)
endif

hl-logs-nginx: ## Show nginx container logs piped through hl
ifeq ($(INSIDE_DOCKER_CONTAINER), 0)
	@docker logs --since 1h baander-nginx 2>&1 | $(HL) -s -L
else
	$(ERROR_ONLY_FOR_HOST)
endif

hl-logs-nginx-f: ## Follow nginx container logs piped through hl
ifeq ($(INSIDE_DOCKER_CONTAINER), 0)
	@docker logs -f baander-nginx 2>&1 | $(HL) -F -L
else
	$(ERROR_ONLY_FOR_HOST)
endif

hl-logs-all: ## Show logs from all containers piped through hl
ifeq ($(INSIDE_DOCKER_CONTAINER), 0)
	@docker compose logs --since 1h 2>&1 | $(HL) -s -L
else
	$(ERROR_ONLY_FOR_HOST)
endif

hl-logs-all-f: ## Follow logs from all containers piped through hl
ifeq ($(INSIDE_DOCKER_CONTAINER), 0)
	@docker compose logs -f 2>&1 | $(HL) -F -L
else
	$(ERROR_ONLY_FOR_HOST)
endif

hl-logs-error: ## Show only error-level app logs via hl
ifeq ($(INSIDE_DOCKER_CONTAINER), 0)
	@docker logs --since 1h baander-app 2>&1 | $(HL) -s -L -l error
else
	$(ERROR_ONLY_FOR_HOST)
endif

hl-logs-warn: ## Show warnings and above from app logs via hl
ifeq ($(INSIDE_DOCKER_CONTAINER), 0)
	@docker logs --since 1h baander-app 2>&1 | $(HL) -s -L -l warning
else
	$(ERROR_ONLY_FOR_HOST)
endif

drop-migrate: ## Drops databases and runs all migrations for the main/test databases
	@make exec cmd="php bin/console doctrine:database:drop --force"
	@make exec cmd="php bin/console doctrine:database:create"
	@make exec cmd="php bin/console doctrine:migrations:migrate --no-interaction"
	@make exec cmd="php bin/console doctrine:database:drop --force --env=test"
	@make exec cmd="php bin/console doctrine:database:create --env=test"
	@make exec cmd="php bin/console doctrine:migrations:migrate --no-interaction --env=test"

migrate-no-test: ## Runs all migrations for main database
	@make exec cmd="php bin/console doctrine:migrations:migrate --no-interaction"

migrate: ## Runs all migrations for main/test databases
	@make exec cmd="php bin/console doctrine:migrations:migrate --no-interaction"
	@make exec cmd="php bin/console doctrine:migrations:migrate --no-interaction --env=test"

migrate-dev: ## Runs all migrations for main database only
	@make exec cmd="php bin/console doctrine:migrations:migrate --no-interaction"

phpunit: ## Runs PhpUnit tests
	@make exec cmd="./vendor/bin/phpunit -c phpunit.xml --coverage-html reports/coverage $(PHPUNIT_OPTIONS) --coverage-clover reports/clover.xml --log-junit reports/junit.xml"

paratest: ## Runs tests in parallel via paratest
	@make exec cmd="./vendor/bin/paratest --processes auto --tmp-dir var $(PARATEST_OPTIONS)"

phpstan: ## Runs PHPStan static analysis
	@make exec-bash cmd="XDEBUG_MODE=off php ./vendor/bin/phpstan analyse --memory-limit=512M $(PHPSTAN_OPTIONS)"

phpstan-baseline: ## Generates PHPStan baseline for existing errors
	@make exec-bash cmd="XDEBUG_MODE=off php ./vendor/bin/phpstan analyse --memory-limit=512M --generate-baseline phpstan-baseline.neon"

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

ci: ## Run full CI pipeline (lint, static analysis, architecture, tests)
	@echo "\033[34m[ci] composer validate\033[39m"
	@make exec cmd="composer validate --no-check-version"
	@echo "\033[34m[ci] composer normalize --dry-run\033[39m"
	@make exec cmd="composer normalize --dry-run"
	@echo "\033[34m[ci] phpstan\033[39m"
	@make exec-bash cmd="XDEBUG_MODE=off php ./vendor/bin/phpstan analyse --memory-limit=512M --no-progress"
	@echo "\033[34m[ci] deptrac\033[39m"
	@make exec cmd="vendor/bin/deptrac analyse --no-cache --no-progress"
	@echo "\033[34m[ci] phpunit\033[39m"
	@make exec-bash cmd="XDEBUG_MODE=off ./vendor/bin/phpunit -c phpunit.xml --coverage-html reports/coverage --coverage-clover reports/clover.xml --log-junit reports/junit.xml"
	@echo "\033[32m[ci] All checks passed.\033[39m"

swoole-dev: ## Clear cache and start Swoole dev server (foreground)
	@make exec-bash cmd="docker exec baander-app php bin/dev-server"

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
