#https://stackoverflow.com/a/44061904/3929620
.PHONY: all install test deploy setup check set-env wait up install-node install-wordpress test-node test-wordpress deploy-develop deploy-production clean-node clean-wordpress down help

include .env

PLUGIN_NAME ?=
PLUGIN_VERSION ?=

MARIADB_TAG ?= latest
MARIADB_ALLOW_EMPTY_PASSWORD ?= yes
MARIADB_USER ?= user
MARIADB_PASSWORD ?=
MARIADB_DATABASE ?= wordpress

WORDPRESS_TAG ?= latest
WORDPRESS_ALLOW_EMPTY_PASSWORD ?= yes
WORDPRESS_DATABASE_HOST ?= mariadb
WORDPRESS_DATABASE_PORT_NUMBER ?= 3306
WORDPRESS_DATABASE_NAME ?= wordpress
WORDPRESS_DATABASE_USER ?= user
WORDPRESS_DATABASE_PASSWORD ?=
WORDPRESS_USERNAME ?= admin
WORDPRESS_PASSWORD ?= password
WORDPRESS_PLUGINS ?=
WORDPRESS_SMTP_HOST ?= mailpit
WORDPRESS_SMTP_PORT_NUMBER ?= 1025
WORDPRESS_SMTP_USER ?=
WORDPRESS_SMTP_PASSWORD ?=
WORDPRESS_SMTP_PROTOCOL ?= tls

NODE_TAG ?= latest
NODE_PORT ?= 3000
NODE_ENV ?= develop
NODE_DEBUG ?=
NODE_LOG_LEVEL ?=

PHPMYADMIN_TAG ?= latest
PHPMYADMIN_HTTP_PORT ?= 8080
PHPMYADMIN_HTTPS_PORT ?= 8443
PHPMYADMIN_ALLOW_NO_PASSWORD ?= yes
PHPMYADMIN_DATABASE_HOST ?= mariadb
PHPMYADMIN_DATABASE_USER ?= user
PHPMYADMIN_DATABASE_PASSWORD ?=
PHPMYADMIN_DATABASE_PORT_NUMBER ?= 3306
PHPMYADMIN_DATABASE_ENABLE_SSL ?= no
PHPMYADMIN_DATABASE_SSL_KEY ?=
PHPMYADMIN_DATABASE_SSL_CERT ?=
PHPMYADMIN_DATABASE_SSL_CA ?=
PHPMYADMIN_DATABASE_SSL_CA_PATH ?=
PHPMYADMIN_DATABASE_SSL_CIPHERS ?=
PHPMYADMIN_DATABASE_SSL_VERIFY ?= yes

MAILPIT_TAG ?= latest
MAILPIT_HTTP_PORT ?= 8025
MAILPIT_MAX_MESSAGES ?= 5000

OPENAI_KEY ?=

PHPSTAN_PRO_WEB_PORT ?=

GITHUB_TOKEN ?=

FRUGAN_UFWUFACF_CACHE_BUSTING_ENABLED ?= false

MODE ?= develop

DOCKER_COMPOSE=docker compose
WORDPRESS_CONTAINER_NAME=wordpress
WORDPRESS_CONTAINER_USER=root
NODE_CONTAINER_NAME=node
NODE_CONTAINER_USER=root
NODE_CONTAINER_WORKSPACE_DIR=/app
TMP_DIR=tmp
DIST_DIR=dist
SVN_DIR=svn
SVN_ASSETS_DIR=.wordpress-org

SVN_AUTH := $(if $(and $(SVN_USERNAME),$(SVN_PASSWORD)),--username $(SVN_USERNAME) --password $(SVN_PASSWORD),)

all: setup up

setup: check .gitconfig docker-compose.override.yml $(TMP_DIR)/certs $(TMP_DIR)/wait-for-it.sh set-env

install: all wait install-node install-wordpress

test: setup test-node test-wordpress

deploy: install deploy-zip
ifeq ($(and $(GITHUB_ACTIONS),$(MODE)),false production)
	deploy-svn
endif

check:
	@echo "Checking requirements"
	@command -v curl >/dev/null 2>&1 || { echo >&2 "curl is required but not installed. Aborting."; exit 1; }
	@command -v git >/dev/null 2>&1 || { echo >&2 "git is required but not installed. Aborting."; exit 1; }
	@command -v rsync >/dev/null 2>&1 || { echo >&2 "rsync is required but not installed. Aborting."; exit 1; }
	@command -v zip >/dev/null 2>&1 || { echo >&2 "zip is required but not installed. Aborting."; exit 1; }
ifeq ($(and $(GITHUB_ACTIONS),$(MODE)),true production)
	@command -v svn >/dev/null 2>&1 || { echo >&2 "svn is required but not installed. Aborting."; exit 1; }
endif

.gitconfig: 
	@echo "Setting up .gitconfig"
	@cp -a .gitconfig.dist .gitconfig
	@git config --local include.path ../.gitconfig

docker-compose.override.yml: 
	@echo "Setting up docker-compose.override.yml"
	@cp -a docker-compose.override.yml.dist docker-compose.override.yml

$(TMP_DIR)/certs:
	@echo "Generating SSL certificates"
	@mkdir -p $(TMP_DIR)/certs
	@mkcert -cert-file "$(TMP_DIR)/certs/server.crt" -key-file "$(TMP_DIR)/certs/server.key" localhost 127.0.0.1 ::1 bs-local.com "*.bs-local.com"
	@chmod +r $(TMP_DIR)/certs/server.*

$(TMP_DIR)/wait-for-it.sh:
	@echo "Downloading wait-for-it.sh"
	@mkdir -p $(TMP_DIR)
	@curl -o $(TMP_DIR)/wait-for-it.sh https://raw.githubusercontent.com/vishnubob/wait-for-it/master/wait-for-it.sh
	@chmod +x $(TMP_DIR)/wait-for-it.sh

set-env:
	@echo "Setting environment variables"
ifeq ($(PLUGIN_NAME),)
	@$(eval PLUGIN_NAME := $(shell basename `git rev-parse --show-toplevel`))
	@if [ -z "$(PLUGIN_NAME)" ]; then \
		echo "PLUGIN_NAME is not set and could not be determined."; \
		exit 1; \
	fi
endif
ifeq ($(PLUGIN_VERSION),)
	@$(eval PLUGIN_VERSION := $(shell git describe --tags --abbrev=0 2>/dev/null | sed 's/^v//'))
	@if [ -z "$(PLUGIN_VERSION)" ]; then \
		echo "No git tags found. Please create a tag before running make."; \
		exit 1; \
	fi
endif

wait:
	@echo "Waiting for services to be ready"
	@$(TMP_DIR)/wait-for-it.sh localhost:80 --timeout=300 --strict -- echo "WordPress is up"
	@$(TMP_DIR)/wait-for-it.sh localhost:$(NODE_PORT) --timeout=300 --strict -- echo "Node is up"

	@echo "Waiting for WordPress to complete setup"
#https://cardinalby.github.io/blog/post/github-actions/implementing-deferred-steps/
# method #1
#	@$(DOCKER_COMPOSE) exec -u$(WORDPRESS_CONTAINER_USER) $(WORDPRESS_CONTAINER_NAME) sh -c 'timeout=300; while [ $$timeout -gt 0 ]; do \
#		[ -f $${WORDPRESS_CONF_FILE:-/bitnami/wordpress/wp-config.php} ] && break; \
#		echo "Waiting for wp-config.php ($$timeout seconds left)..."; \
#		sleep 5; timeout=$$((timeout - 5)); \
#	done; \
#	[ $$timeout -gt 0 ] || { echo "Error: Timeout reached, wp-config.php not found"; exit 1; }'

# method #2
	@./build/docker/logs-catcher.sh $(WORDPRESS_CONTAINER_NAME) "WordPress setup finished" 180

up:
	@echo "Starting docker compose services"
	@MARIADB_TAG=${MARIADB_TAG} WORDPRESS_TAG=${WORDPRESS_TAG} NODE_TAG=${NODE_TAG} $(DOCKER_COMPOSE) up -d --build

install-node: clean-node
	@echo "[node] Installing dependencies"
	@$(DOCKER_COMPOSE) exec -u$(NODE_CONTAINER_USER) $(NODE_CONTAINER_NAME) sh -c 'cd $(NODE_CONTAINER_WORKSPACE_DIR)/build/front && npm install && npm run develop && npm run production'

install-wordpress: clean-wordpress
ifneq ($(GITHUB_TOKEN),)
	@echo "[wordpress] Updating composer config ($(MODE))"
	@$(DOCKER_COMPOSE) exec -u$(WORDPRESS_CONTAINER_USER) $(WORDPRESS_CONTAINER_NAME) sh -c 'composer config -g github-oauth.github.com $(GITHUB_TOKEN)'
endif

	@echo "[wordpress] Initializing git repository ($(MODE))"
#FIXED: safe.directory avoids Github fatal error: detected dubious ownership in repository
	@$(DOCKER_COMPOSE) exec -u$(WORDPRESS_CONTAINER_USER) $(WORDPRESS_CONTAINER_NAME) sh -c 'cd /tmp/$(PLUGIN_NAME)-plugin && { \
		git init; \
		git config --global user.email "you@example.com"; \
		git config --global user.name "Your Name"; \
		git config --global --add safe.directory /tmp/$(PLUGIN_NAME)-plugin; \
	}'

	@echo "[wordpress] Creating symbolic links ($(MODE))"
	@$(DOCKER_COMPOSE) exec -u$(WORDPRESS_CONTAINER_USER) $(WORDPRESS_CONTAINER_NAME) sh -c 'ln -sfn /tmp/$(PLUGIN_NAME)-plugin $${WORDPRESS_BASE_DIR:-/bitnami/wordpress}/wp-content/plugins/$(PLUGIN_NAME)'
	@$(DOCKER_COMPOSE) exec -u$(WORDPRESS_CONTAINER_USER) $(WORDPRESS_CONTAINER_NAME) sh -c 'ln -sfn /tmp/$(PLUGIN_NAME)-plugin/tests/data/wp-cfm $${WORDPRESS_BASE_DIR:-/bitnami/wordpress}/wp-content/config'

	@echo "[wordpress] Updating wp-config.php ($(MODE))"
	@$(DOCKER_COMPOSE) exec -u$(WORDPRESS_CONTAINER_USER) $(WORDPRESS_CONTAINER_NAME) sh -c "sed -i '/define('\''FRUGAN_UFWUFACF_CACHE_BUSTING_ENABLED'\'',/d' $${WORDPRESS_BASE_DIR:-/bitnami/wordpress}/wp-config.php && sed -i '1a define('\''FRUGAN_UFWUFACF_CACHE_BUSTING_ENABLED'\'', ${FRUGAN_UFWUFACF_CACHE_BUSTING_ENABLED});' $${WORDPRESS_BASE_DIR:-/bitnami/wordpress}/wp-config.php"
	
	@echo "[wordpress] Installing dependencies ($(MODE))"
# PHP 7.x and 8.x interpret composer.json's `extra.installer-paths` differently, perhaps due to different versions of Composer.
# With PHP 7.x `cd $${WORDPRESS_BASE_DIR:-/bitnami/wordpress}/wp-content/plugins/$(PLUGIN_NAME)` and
# `extra.installer-paths."../{$name}/"` in the composer.json seems to be sufficient, while with PHP 8.x it is not.
# Adding Composer's `--working-dir` option with PHP 8.x doesn't work.
# For this reason, the absolute path `extra.installer-paths` had to be specified in the composer.json.
ifeq ($(MODE),production)
	@$(DOCKER_COMPOSE) exec -u$(WORDPRESS_CONTAINER_USER) $(WORDPRESS_CONTAINER_NAME) sh -c 'cd $${WORDPRESS_BASE_DIR:-/bitnami/wordpress}/wp-content/plugins/$(PLUGIN_NAME) && composer install --optimize-autoloader --classmap-authoritative --no-dev --no-interaction'
else
	@$(DOCKER_COMPOSE) exec -u$(WORDPRESS_CONTAINER_USER) $(WORDPRESS_CONTAINER_NAME) sh -c 'cd $${WORDPRESS_BASE_DIR:-/bitnami/wordpress}/wp-content/plugins/$(PLUGIN_NAME) && composer update --optimize-autoloader --no-interaction'
endif
	
	@echo "[wordpress] Activate WP-CFM plugin ($(MODE))"
	@$(DOCKER_COMPOSE) exec -u$(WORDPRESS_CONTAINER_USER) $(WORDPRESS_CONTAINER_NAME) sh -c 'wp plugin activate wp-cfm --allow-root'
	
	@echo "[wordpress] Pulling WP-CFM bundles ($(MODE))"
	@$(DOCKER_COMPOSE) exec -u$(WORDPRESS_CONTAINER_USER) $(WORDPRESS_CONTAINER_NAME) sh -c 'for file in $${WORDPRESS_BASE_DIR:-/bitnami/wordpress}/wp-content/config/*.json; do wp config pull $$(basename $$file .json) --allow-root; done'
	
	@echo "[wordpress] Cleaning ACF data ($(MODE))"
	@$(DOCKER_COMPOSE) exec -u$(WORDPRESS_CONTAINER_USER) $(WORDPRESS_CONTAINER_NAME) sh -c 'wp acf clean --allow-root'
	
	@echo "[wordpress] Importing ACF JSON files ($(MODE))"
	@$(DOCKER_COMPOSE) exec -u$(WORDPRESS_CONTAINER_USER) $(WORDPRESS_CONTAINER_NAME) sh -c 'for file in $${WORDPRESS_BASE_DIR:-/bitnami/wordpress}/wp-content/plugins/$(PLUGIN_NAME)/tests/data/acf/*.json; do wp acf import --json_file=$${file} --allow-root; done'
	
	@echo "[wordpress] Flushing rewrite rules ($(MODE))"
	@$(DOCKER_COMPOSE) exec -u$(WORDPRESS_CONTAINER_USER) $(WORDPRESS_CONTAINER_NAME) sh -c 'wp rewrite flush --allow-root'

	@echo "[wordpress] Changing folders permissions ($(MODE))"
# avoids write permission errors when PHP writes w/ 1001 user
	@$(DOCKER_COMPOSE) exec -u$(WORDPRESS_CONTAINER_USER) $(WORDPRESS_CONTAINER_NAME) sh -c 'chmod -Rf o+w /tmp/$(PLUGIN_NAME)-plugin/tests/data/wp-cfm'
	@$(DOCKER_COMPOSE) exec -u$(WORDPRESS_CONTAINER_USER) $(WORDPRESS_CONTAINER_NAME) sh -c 'chmod o+w /tmp/$(PLUGIN_NAME)-plugin/symlink'

	@echo "[wordpress] Changing folders owner ($(MODE))"
	@$(DOCKER_COMPOSE) exec -u $(WORDPRESS_CONTAINER_USER) $(WORDPRESS_CONTAINER_NAME) sh -c 'for dir in $${WORDPRESS_BASE_DIR:-/bitnami/wordpress}/wp-content/plugins/*; do if [ "$$(basename $$dir)" != "$(PLUGIN_NAME)" ]; then chown -R 1001 $$dir; fi; done'
	
	@echo "[wordpress] Changing wp-config.php permissions ($(MODE))"
	@$(DOCKER_COMPOSE) exec -u$(WORDPRESS_CONTAINER_USER) $(WORDPRESS_CONTAINER_NAME) sh -c 'chmod 666 $${WORDPRESS_BASE_DIR:-/bitnami/wordpress}/wp-config.php'
	
#FIXME: nullmailer doesn't stay started
	@echo "[wordpress] Starting nullmailer ($(MODE))"
	@$(DOCKER_COMPOSE) exec -u$(WORDPRESS_CONTAINER_USER) $(WORDPRESS_CONTAINER_NAME) sh -c 'service nullmailer start'

test-node:
	@echo "[node] Running tests"
	@$(DOCKER_COMPOSE) exec -u$(NODE_CONTAINER_USER) $(NODE_CONTAINER_NAME) sh -c 'cd $(NODE_CONTAINER_WORKSPACE_DIR)/build/front && npm run test'

test-wordpress:
	@echo "[wordpress] Updating git repository"
	@$(DOCKER_COMPOSE) exec -u$(WORDPRESS_CONTAINER_USER) $(WORDPRESS_CONTAINER_NAME) sh -c 'cd $${WORDPRESS_BASE_DIR:-/bitnami/wordpress}/wp-content/plugins/$(PLUGIN_NAME) && git add .'
	
	@echo "[wordpress] Running tests"
ifeq ($(GITHUB_ACTIONS),true)
	@$(DOCKER_COMPOSE) exec -u$(WORDPRESS_CONTAINER_USER) $(WORDPRESS_CONTAINER_NAME) sh -c 'cd $${WORDPRESS_BASE_DIR:-/bitnami/wordpress}/wp-content/plugins/$(PLUGIN_NAME) && ./vendor/bin/grumphp run --no-interaction'
else
	@$(DOCKER_COMPOSE) exec -u$(WORDPRESS_CONTAINER_USER) $(WORDPRESS_CONTAINER_NAME) sh -c 'cd $${WORDPRESS_BASE_DIR:-/bitnami/wordpress}/wp-content/plugins/$(PLUGIN_NAME) && ./vendor/bin/grumphp run'
endif

deploy-zip:
	@echo "Deploying to zip file"
	@mkdir -p $(DIST_DIR)/$(PLUGIN_NAME)
	@cd $(PLUGIN_NAME) && rsync -av --delete --exclude-from=exclude_from.txt --include-from=include_from.txt . ../$(DIST_DIR)/$(PLUGIN_NAME)/
	@cd $(DIST_DIR)/$(PLUGIN_NAME) && zip -r ../$(PLUGIN_NAME).zip .

deploy-svn:
	@echo "Deploying to WordPress SVN"
	@if ! svn ls https://plugins.svn.wordpress.org/$(PLUGIN_NAME)/ >/dev/null 2>&1; then \
		echo "SVN repository does not exist. Aborting."; \
		exit 1; \
	fi
	@svn $(SVN_AUTH) checkout https://plugins.svn.wordpress.org/$(PLUGIN_NAME)/ $(TMP_DIR)/$(SVN_DIR)
	@CURRENT_BRANCH=$$(git rev-parse --abbrev-ref HEAD); \
	if [[ "$$CURRENT_BRANCH" != support/* ]]; then \
		echo "Deploying to trunk and assets"; \
		rsync -av --delete $(DIST_DIR)/$(PLUGIN_NAME)/ $(TMP_DIR)/$(SVN_DIR)/trunk/; \
		rsync -av --delete $(SVN_ASSETS_DIR)/ $(TMP_DIR)/$(SVN_DIR)/assets/; \
	fi	
	@if [ ! -d "$(TMP_DIR)/$(SVN_DIR)/tags/$(PLUGIN_VERSION)" ]; then \
		echo "Deploying to tags"; \
		mkdir -p $(TMP_DIR)/$(SVN_DIR)/tags/$(PLUGIN_VERSION); \
		rsync -av --delete $(DIST_DIR)/$(PLUGIN_NAME)/ $(TMP_DIR)/$(SVN_DIR)/tags/$(PLUGIN_VERSION)/; \
	fi
	@cd $(TMP_DIR)/$(SVN_DIR) && svn add --force * --auto-props --parents --depth infinity -q
	@cd $(TMP_DIR)/$(SVN_DIR) && svn $(SVN_AUTH) commit -m "release $(PLUGIN_VERSION)"
	@rm -rf $(TMP_DIR)/$(SVN_DIR) $(DIST_DIR)/$(PLUGIN_NAME)

clean-node: 
	@echo "[node] Cleaning artifacts"
	@$(DOCKER_COMPOSE) exec -u$(NODE_CONTAINER_USER) $(NODE_CONTAINER_NAME) sh -c 'cd $(NODE_CONTAINER_WORKSPACE_DIR) && rm -rf build/front/node_modules build/front/package-lock.json $(PLUGIN_NAME)/asset'

clean-wordpress: 
	@echo "[wordpress] Cleaning artifacts"
	@$(DOCKER_COMPOSE) exec -u$(WORDPRESS_CONTAINER_USER) $(WORDPRESS_CONTAINER_NAME) sh -c 'if [ -d "$${WORDPRESS_BASE_DIR:-/bitnami/wordpress}/wp-content/plugins/$(PLUGIN_NAME)" ]; then cd $${WORDPRESS_BASE_DIR:-/bitnami/wordpress}/wp-content/plugins/$(PLUGIN_NAME) && rm -rf .git vendor composer.lock && find symlink -mindepth 1 -maxdepth 1 -type d | xargs rm -rf; fi'
	@rm -rf $(DIST_DIR)/*

down: 
	@echo "Stopping docker compose services"
	@$(DOCKER_COMPOSE) down

help:
	@echo "Makefile targets:"
	@echo "  all           - Start environment"
	@echo "  install       - Start environment and install dependencies"
	@echo "  test          - Run tests"
	@echo "  deploy        - Start environment, install dependencies and deploy to $(MODE)"
	@echo "  down          - Stop environment"
