.DEFAULT_GOAL := help
SHELL=/bin/bash
COMPOSER_ROOT=composer
PLUGIN_NAME=payplug/sylius-payplug-plugin
SYLIUS_VERSION=1.12.0
SYMFONY_VERSION=6.1
PHP_VERSION=8.1
TEST_DIRECTORY=tests/Application
YARN=cd tests/Application && yarn
CONSOLE=cd tests/Application && php bin/console -e test
COMPOSER=cd tests/Application && composer

###
### DEVELOPMENT
### ¯¯¯¯¯¯¯¯¯¯¯

install: sylius ## Install Plugin on Sylius [SYLIUS_VERSION=1.12.0] [SYMFONY_VERSION=6.1] [PHP_VERSION=8.1]
.PHONY: install

reset: ## Remove dependencies
	rm -rf tests/Application
.PHONY: reset

phpunit: phpunit-configure phpunit-run ## Run PHPUnit
.PHONY: phpunit

###
### OTHER
### ¯¯¯¯¯¯

sylius: sylius-standard update-dependencies install-plugin install-sylius
.PHONY: sylius

sylius-standard:
	${COMPOSER_ROOT} create-project sylius/sylius-standard ${TEST_DIRECTORY} "~${SYLIUS_VERSION}" --no-install --no-scripts
	${COMPOSER} config allow-plugins true
	${COMPOSER} require sylius/sylius:"~${SYLIUS_VERSION}"

update-dependencies:
	${COMPOSER} config extra.symfony.require "~${SYMFONY_VERSION}"
	${COMPOSER} require --dev donatj/mock-webserver:^2.1 --no-scripts --no-update
# FIX since https://github.com/Sylius/Sylius/pull/13215 is not merged
	${COMPOSER} require doctrine/dbal:"^2.6" doctrine/orm:"^2.9" --no-scripts --no-update
ifeq ($(shell [[ $(SYMFONY_VERSION) == 4.4 && $(PHP_VERSION) == 7.4 ]] && echo true ),true)
	${COMPOSER} require sylius/admin-api-bundle:1.10 --no-scripts --no-update
endif
ifeq ($(SYLIUS_VERSION), 1.8.0)
	${COMPOSER} update --no-progress --no-scripts --prefer-dist -n
endif
	${COMPOSER} require symfony/asset:^${SYMFONY_VERSION} --no-scripts --no-update
	${COMPOSER} update --no-progress -n

install-plugin:
	${COMPOSER} config repositories.plugin '{"type": "path", "url": "../../"}'
	${COMPOSER} config extra.symfony.allow-contrib true
	${COMPOSER} config minimum-stability "dev"
	${COMPOSER} config prefer-stable true
	${COMPOSER} req ${PLUGIN_NAME}:* --prefer-source --no-scripts
	${COMPOSER} symfony:recipes:install "${PLUGIN_NAME}" --force

	cp -r install/Application tests
	sed -i "4a \ \ \ \ form_themes: ['form/form_gateway_config_row.html.twig']" ${TEST_DIRECTORY}/config/packages/twig.yaml
	mkdir -p ${TEST_DIRECTORY}/templates/form/
	cp -R src/Resources/views/form/* ${TEST_DIRECTORY}/templates/form/

# As of sylius/refund-plugin 1.2 the folder does not exist anymore
ifneq ($(PHP_VERSION), 8)
	mkdir -p ${TEST_DIRECTORY}/templates/bundles/SyliusAdminBundle/
	cp -R src/Resources/views/SyliusAdminBundle/* ${TEST_DIRECTORY}/templates/bundles/SyliusAdminBundle/
endif

install-sylius:
	#${CONSOLE} sylius:install -n -s default
	${CONSOLE} doctrine:database:create -n
	${CONSOLE} messenger:setup-transports -n
	${CONSOLE} doctrine:migration:migrate -n
	${CONSOLE} sylius:fixture:load -n
	${YARN} install
	${YARN} build
	${YARN} gulp
	${CONSOLE} translation:extract en PayPlugSyliusPayPlugPlugin --dump-messages
	${CONSOLE} translation:extract fr PayPlugSyliusPayPlugPlugin --dump-messages

	${CONSOLE} cache:clear

phpunit-configure:
	cp phpunit.xml.dist ${TEST_DIRECTORY}/phpunit.xml
	echo -e "\nMOCK_SERVER_HOST=localhost\nMOCK_SERVER_PORT=8987\n" >> ${TEST_DIRECTORY}/.env.test.local

phpunit-run:
	cd ${TEST_DIRECTORY} && ./vendor/bin/phpunit

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
help: ## Dislay this help
	@IFS=$$'\n'; for line in `grep -h -E '^[a-zA-Z_#-]+:?.*?##.*$$' $(MAKEFILE_LIST)`; do if [ "$${line:0:2}" = "##" ]; then \
	echo $$line | awk 'BEGIN {FS = "## "}; {printf "\033[33m    %s\033[0m\n", $$2}'; else \
	echo $$line | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-30s\033[0m%s\n", $$1, $$2}'; fi; \
	done; unset IFS;
.PHONY: help
