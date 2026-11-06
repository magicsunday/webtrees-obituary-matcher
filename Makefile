SHELL = /bin/bash

.SILENT:

# Do not print "Entering directory ..."
MAKEFLAGS += --no-print-directory

.PHONY: no_targets__ *
	no_targets__:

.DEFAULT_GOAL := help

# Docker Compose detection
COMPOSE_BIN := $(shell \
	if command -v docker >/dev/null 2>&1; then \
		if docker compose version >/dev/null 2>&1; then \
			echo "docker compose"; \
		elif command -v docker-compose >/dev/null 2>&1; then \
			echo "docker-compose"; \
		else \
			echo "echo 'Error: No Docker Compose found' && exit 1"; \
		fi; \
	else \
		echo "echo 'Error: Docker not found' && exit 1"; \
	fi)

COMPOSE_RUN := $(COMPOSE_BIN) run --rm buildbox

# The lang recipes run in the webtrees-docker tooling image, which carries gettext, PHP and Composer,
# as the numeric user and group of the caller, so that no root-owned file lands in the checkout. The
# module has no compose file of its own, because that would hide the buildbox service of the webtrees
# project, so the runner is a plain docker run. The CI i18n freshness gate overrides this and
# COMPOSE_RUN with an empty value, so the recipes run directly inside the image it already spawned.
TOOLING_IMAGE ?= ghcr.io/magicsunday/webtrees-docker-module-base:node24
COMPOSE_RUN_USER = docker run --rm --user $(shell id -u):$(shell id -g) -e HOME=/tmp -v "$(CURDIR)":/app -w /app $(TOOLING_IMAGE)

# Includes
-include Make/*.mk
-include Make/**/*.mk

# Argument fix workaround
%:
	@:
