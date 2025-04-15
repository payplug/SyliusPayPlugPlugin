.DEFAULT_GOAL := help
SHELL=/bin/bash
COMPOSER_ROOT=composer
TEST_DIRECTORY=tests/Application
INSTALL_DIRECTORY=install/Application
CONSOLE=cd ${TEST_DIRECTORY} && php bin/console -e test
COMPOSER=cd ${TEST_DIRECTORY} && composer
YARN=cd ${TEST_DIRECTORY} && yarn

SYLIUS_VERSION=1.14.0
SYMFONY_VERSION=6.4
PHP_VERSION=8.2
PLUGIN_NAME=payplug/sylius-payplug-plugin

###
### DEVELOPMENT
### ¯¯¯¯¯¯¯¯¯¯¯

install: sylius ## Install Plugin on Sylius [SYLIUS_VERSION=1.14.0] [SYMFONY_VERSION=6.4] [PHP_VERSION=8.2]
.PHONY: install

reset: ## Remove dependencies
ifneq ("$(wildcard ${TEST_DIRECTORY}/bin/console)","")
	${CONSOLE} doctrine:database:drop --force --if-exists || true
endif
	rm -rf ${TEST_DIRECTORY}
.PHONY: reset

phpunit: phpunit-configure phpunit-run ## Run PHPUnit
.PHONY: phpunit

###
### OTHER
### ¯¯¯¯¯¯

sylius: sylius-standard install-plugin install-sylius
.PHONY: sylius

sylius-standard:
ifeq ($(shell [[ $(SYLIUS_VERSION) == *dev ]] && echo true ),true)
	${COMPOSER_ROOT} create-project sylius/sylius-standard:${SYLIUS_VERSION} ${TEST_DIRECTORY} --no-install --no-scripts
else
	${COMPOSER_ROOT} create-project sylius/sylius-standard ${TEST_DIRECTORY} "~${SYLIUS_VERSION}" --no-install --no-scripts
endif
	${COMPOSER} config allow-plugins true
ifeq ($(shell [[ $(SYLIUS_VERSION) == *dev ]] && echo true ),true)
	${COMPOSER} require sylius/sylius:"${SYLIUS_VERSION}"
else
	${COMPOSER} require sylius/sylius:"~${SYLIUS_VERSION}"
endif

install-plugin:
	${COMPOSER} config repositories.plugin '{"type": "path", "url": "../../"}'
	${COMPOSER} config extra.symfony.allow-contrib true
	${COMPOSER} config minimum-stability "dev"
	${COMPOSER} config prefer-stable true
	${COMPOSER} require "${PLUGIN_NAME}:*" --prefer-source --no-scripts

	cp -r ${INSTALL_DIRECTORY} tests
	sed -i "4a \ \ \ \ form_themes: ['form/form_gateway_config_row.html.twig']" ${TEST_DIRECTORY}/config/packages/twig.yaml
	mkdir -p ${TEST_DIRECTORY}/templates/form/
	cp -R templates/form/* ${TEST_DIRECTORY}/templates/form/

# As of sylius/refund-plugin 1.2 the folder does not exist anymore
ifneq ($(PHP_VERSION), 8)
	mkdir -p ${TEST_DIRECTORY}/templates/bundles/SyliusAdminBundle/
	cp -R templates/SyliusAdminBundle/* ${TEST_DIRECTORY}/templates/bundles/SyliusAdminBundle/
endif

install-sylius:
	#${CONSOLE} sylius:install -n -s default
	${CONSOLE} doctrine:database:create -n --if-not-exists
	${CONSOLE} messenger:setup-transports -n
	${CONSOLE} doctrine:migration:migrate -n
	${CONSOLE} sylius:fixture:load -n
	${CONSOLE} assets:install --no-ansi
	${YARN} install
	${YARN} build
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
help: ## Display this help
	@IFS=$$'\n'; for line in `grep -h -E '^[a-zA-Z_#-]+:?.*?##.*$$' $(MAKEFILE_LIST)`; do if [ "$${line:0:2}" = "##" ]; then \
	echo $$line | awk 'BEGIN {FS = "## "}; {printf "\033[33m    %s\033[0m\n", $$2}'; else \
	echo $$line | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-30s\033[0m%s\n", $$1, $$2}'; fi; \
	done; unset IFS;
.PHONY: help
