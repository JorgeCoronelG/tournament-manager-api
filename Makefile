# Atajos para trabajar con Docker. Solo requiere Docker instalado.
# Uso: make <comando>   (ejemplo: make test)

RUN = docker compose run --rm --no-deps app

.PHONY: help setup up down build install test stan lint format artisan composer shell logs

help: ## Muestra los comandos disponibles
	@grep -E '^[a-z-]+:.*##' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  make %-10s %s\n", $$1, $$2}'

setup: build install ## Primera vez: construye, instala dependencias, crea .env y migra
	@test -f .env || cp .env.example .env
	$(RUN) php artisan key:generate
	docker compose up -d mysql
	docker compose run --rm app php artisan migrate

build: ## Construye la imagen de PHP
	docker compose build

install: ## Instala las dependencias de Composer
	$(RUN) composer install

up: ## Levanta API, MySQL y phpMyAdmin
	docker compose up -d

down: ## Detiene los contenedores
	docker compose down

test: ## Ejecuta los tests (SQLite en memoria, no necesita MySQL)
	$(RUN) php vendor/bin/phpunit $(ARGS)

stan: ## Análisis estático con Larastan
	$(RUN) php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress

lint: ## Verifica el estilo de código con Pint (no modifica archivos)
	$(RUN) vendor/bin/pint --test

format: ## Corrige el estilo de código con Pint
	$(RUN) vendor/bin/pint

artisan: ## Ejecuta artisan. Ejemplo: make artisan cmd="route:list"
	docker compose run --rm app php artisan $(cmd)

composer: ## Ejecuta composer. Ejemplo: make composer cmd="require vendor/paquete"
	$(RUN) composer $(cmd)

shell: ## Abre una terminal dentro del contenedor de la app
	docker compose run --rm app bash

logs: ## Muestra los logs de los contenedores
	docker compose logs -f
