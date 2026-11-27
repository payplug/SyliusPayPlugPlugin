.DEFAULT_GOAL := help
SHELL=/bin/bash
COMPOSER_ROOT=composer
TEST_DIRECTORY=tests/TestApplication
CONSOLE=vendor/bin/console
COMPOSER=composer
SYLIUS_VERSION=2.1.0
SYMFONY_VERSION=6.4
SYLIUS_MINOR := $(shell echo '$(SYLIUS_VERSION)' | cut -d. -f1,2)
SYLIUS_2_1 := $(filter 2.1,$(SYLIUS_MINOR))
# TODO: Remove alpha when stable
SYLIUS_PACKAGES = sylius/test-application:"~$(SYLIUS_MINOR).0-alpha@alpha" sylius/sylius:"~$(SYLIUS_MINOR).0"
TEST_APPLICATION_WEBPACK_CAP := node -e 'const fs = require("fs"); const file = "vendor/sylius/test-application/package.json.dist"; const pkg = JSON.parse(fs.readFileSync(file, "utf8")); pkg.resolutions = { ...pkg.resolutions, webpack: ">=5.72 <5.109" }; fs.writeFileSync(file, JSON.stringify(pkg, null, 4) + "\n");'
PLUGIN_NAME=payplug/sylius-payplug-plugin

# Coverage runs inside Docker (PHP 8.2 + PCOV) instead of the host PHP, since the host's default
# `php`/`composer` may resolve to an unrelated version with no coverage driver installed. Assumes
# `vendor/` (and the Sylius test-application) is already installed on the host via `make install`.
IMAGE_DEV := sylius-payplug-plugin-dev
DOCKER_RUN := docker run --rm -v $(CURDIR):/app -w /app -u "$$(id -u):$$(id -g)" -e COMPOSER_HOME=/tmp/composer $(IMAGE_DEV)

###
### DEVELOPMENT
### ¯¯¯¯¯¯¯¯¯¯¯
install: sylius ## Install all dependencies with [SYLIUS_VERSION=2.1.0] (latest patch of that minor) [SYMFONY_VERSION=6.4]
.PHONY: install

reset: ## Remove dependencies
	${CONSOLE} doctrine:database:drop --force --if-exists || true
	rm -rf vendor
.PHONY: reset

phpunit: ## Run PHPUnit tests
	./vendor/bin/phpunit
.PHONY: phpunit

show-versions: ## Print the installed Sylius / Symfony / PHP versions
	@php -r 'echo "PHP ", PHP_VERSION, PHP_EOL;'
	@set -e; for p in sylius/sylius sylius/test-application symfony/framework-bundle; do ${COMPOSER} show $$p | grep -E '^(name|versions) +:'; done
.PHONY: show-versions

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
	${COMPOSER} config extra.symfony.require "~${SYMFONY_VERSION}.0"
	@# Both packages are pinned to the minor of SYLIUS_VERSION (latest patch). sylius/sylius is pinned explicitly so the
	@# installed minor does not depend on what the test-application release requires.
	@# composer.lock is installed first when the running PHP satisfies it. Otherwise (a PHP newer than some locked
	@# packages allow) every package is resolved for the running PHP instead, which rewrites composer.lock: on a local
	@# checkout, `git checkout composer.json composer.lock` restores both files afterwards.
	@# Every sylius/sylius 2.1.x release is affected by advisories left unpatched upstream (Sylius 2.1 is out of security
	@# support, see the 2026-11-18 advisories on https://packagist.org/security-advisories/), and Composer 2.9+ refuses to
	@# resolve them. The ignore applies to the 2.1 minor only, so new advisories on 2.2 still block the install. It is set
	@# for the resolution below only and removed right after, so composer.json keeps no trace of it.
	@# audit.ignore is used because Composer 2.9 does not know policy.advisories.ignore.
	$(if $(SYLIUS_2_1),${COMPOSER} config --json audit.ignore '["sylius/sylius"]')
	if platform=$$(${COMPOSER} check-platform-reqs --lock 2>&1); then \
		${COMPOSER} install -n && ${COMPOSER} require --dev ${SYLIUS_PACKAGES} -n -W; \
	else \
		grep -v ' success *$$' <<< "$$platform"; \
		message="composer.lock does not fit PHP $$(php -r 'echo PHP_VERSION;'): resolving dependencies without the lock"; \
		if [ -n "$${GITHUB_ACTIONS:-}" ]; then echo "::warning::$$message"; else echo "$$message"; fi; \
		${COMPOSER} require --dev ${SYLIUS_PACKAGES} -n --no-update && ${COMPOSER} update -n; \
	fi; status=$$?; $(if $(SYLIUS_2_1),${COMPOSER} config --unset audit;) exit $$status
	@$(MAKE) --no-print-directory show-versions
	@# Sylius 2.1's admin and shop bundles require webpack ^5.72, and webpack 5.109+ breaks the @symfony/stimulus-bridge
	@# loader (Cannot find module 'webpack/lib/dependencies/LoaderDependency'). Sylius 2.2 caps it at >=5.72 <5.109 in
	@# the same files; the same cap is set here for 2.1 as a yarn resolution. It goes into package.json.dist because the
	@# test-application's preinstall script rebuilds package.json from it.
	$(if $(SYLIUS_2_1),$(TEST_APPLICATION_WEBPACK_CAP))
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
