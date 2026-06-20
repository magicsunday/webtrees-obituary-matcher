# =============================================================================
# TARGETS
# =============================================================================

#### CI

.PHONY: test lint cgl-check rector-check stan unit cpd

test: .logo ## Runs the full CI pipeline (lint, cgl, rector, phpstan, cpd, phpunit).
	${COMPOSE_RUN} composer ci:test

lint: .logo ## Runs the PHP linter.
	${COMPOSE_RUN} composer ci:test:php:lint

cgl-check: .logo ## Checks the code style (dry-run).
	${COMPOSE_RUN} composer ci:test:php:cgl

rector-check: .logo ## Checks the rector rules (dry-run).
	${COMPOSE_RUN} composer ci:test:php:rector

stan: .logo ## Runs PHPStan analysis.
	${COMPOSE_RUN} composer ci:test:php:phpstan

unit: .logo ## Runs the PHPUnit tests.
	${COMPOSE_RUN} composer ci:test:php:unit

cpd: .logo ## Runs copy-paste detection (jscpd).
	${COMPOSE_RUN} composer ci:test:cpd


#### Fix

.PHONY: cgl rector

cgl: .logo ## Fixes the code style.
	${COMPOSE_RUN} composer ci:cgl

rector: .logo ## Applies the rector rules.
	${COMPOSE_RUN} composer ci:rector


#### Dependencies

.PHONY: install update

install: .logo ## Installs the composer dependencies.
	${COMPOSE_RUN} composer install

update: .logo ## Updates the composer dependencies.
	${COMPOSE_RUN} composer update
