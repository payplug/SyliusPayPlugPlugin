.DEFAULT_GOAL := help
SHELL=/bin/bash
COMPOSER_ROOT=composer
TEST_DIRECTORY=tests/TestApplication
CONSOLE=vendor/bin/console
COMPOSER=composer
SYLIUS_VERSION=2.1.0
SYMFONY_VERSION=6.4
PLUGIN_NAME=payplug/sylius-payplug-plugin

# Coverage runs inside Docker (PHP 8.2 + PCOV) instead of the host PHP, since the host's default
# `php`/`composer` may resolve to an unrelated version with no coverage driver installed. Assumes
# `vendor/` (and the Sylius test-application) is already installed on the host via `make install`.
IMAGE_DEV := sylius-payplug-plugin-dev
DOCKER_RUN := docker run --rm -v $(CURDIR):/app -w /app -u "$$(id -u):$$(id -g)" -e COMPOSER_HOME=/tmp/composer $(IMAGE_DEV)

###
### DEVELOPMENT
### ¯¯¯¯¯¯¯¯¯¯¯
install: sylius ## Install all dependencies with [SYLIUS_VERSION=2.1.0] [SYMFONY_VERSION=6.4]
.PHONY: install

reset: ## Remove dependencies
	${CONSOLE} doctrine:database:drop --force --if-exists || true
	rm -rf vendor
.PHONY: reset

phpunit: ## Run PHPUnit tests
	./vendor/bin/phpunit
.PHONY: phpunit

build-dev: ## Build the Docker image used to run coverage
	docker build -t $(IMAGE_DEV) .
.PHONY: build-dev

coverage: build-dev ## Run PHPUnit tests with a Clover coverage report (build/logs/clover.xml), via Docker
	$(DOCKER_RUN) composer test-coverage
.PHONY: coverage

###
### OTHER
### ¯¯¯¯¯¯
sylius: install-sylius
.PHONY: sylius

install-sylius:
	@echo "Installing Sylius ${SYLIUS_VERSION} using TestApplication"
	${COMPOSER} config extra.symfony.require "^${SYMFONY_VERSION}"
	${COMPOSER} install
	${COMPOSER} require --dev sylius/test-application:"^${SYLIUS_VERSION}@alpha" -n -W # TODO: Remove alpha when stable
	${COMPOSER} test-application:install



behat-configure: ## Configure Behat
	(cd ${TEST_DIRECTORY} && cp behat.yml.dist behat.yml)
	(cd ${TEST_DIRECTORY} && sed -i "s#vendor/sylius/sylius/src/Sylius/Behat/Resources/config/suites.yml#vendor/${PLUGIN_NAME}/tests/Behat/Resources/suites.yml#g" behat.yml)
	(cd ${TEST_DIRECTORY} && sed -i "s#vendor/sylius/sylius/features#vendor/${PLUGIN_NAME}/features#g" behat.yml)
	(cd ${TEST_DIRECTORY} && sed -i '2i \ \ \ \ - { resource: "../vendor/${PLUGIN_NAME}/tests/Behat/Resources/services.xml\" }' config/services_test.yaml)
	(cd ${TEST_DIRECTORY} && sed -i '3i \ \ \ \ - { resource: "../vendor/${PLUGIN_NAME}/src/Resources/config/services.xml" }' config/services_test.yaml)
	(cd ${TEST_DIRECTORY} && sed -i '4i \ \ \ \ - { resource: "../vendor/sylius/refund-plugin/src/Resources/config/services.xml" }' config/services_test.yaml)
	(cd ${TEST_DIRECTORY} && sed -i '5i \ \ \ \ - { resource: "../vendor/sylius/refund-plugin/tests/Behat/Resources/services.xml" }' config/services_test.yaml)
	(cd ${TEST_DIRECTORY} && sed -i '6i \ \ \ \ - { resource: "services_payplug.yaml" }' config/services_test.yaml)

grumphp:
	vendor/bin/grumphp run

help: SHELL=/bin/bash
help: ## Display this help
	@IFS=$$'\n'; for line in `grep -h -E '^[a-zA-Z_#-]+:?.*?##.*$$' $(MAKEFILE_LIST)`; do if [ "$${line:0:2}" = "##" ]; then \
	echo $$line | awk 'BEGIN {FS = "## "}; {printf "\033[33m    %s\033[0m\n", $$2}'; else \
	echo $$line | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-30s\033[0m%s\n", $$1, $$2}'; fi; \
	done; unset IFS;
.PHONY: help
