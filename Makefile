.PHONY: build up start stop down restart logs test cli cli-leads diagram diagram-leads install shell clean

build: ## Build Docker image
	docker compose build

up: ## Start the web UI (http://localhost:8080)
	docker compose up --build -d

start: ## Start existing containers
	docker compose start

stop: ## Stop containers (preserves state)
	docker compose stop

down: ## Stop and remove containers
	docker compose down

restart: down up ## Restart containers

logs: ## Tail container logs
	docker compose logs -f

test: ## Run the test suite
	docker compose run --rm web vendor/bin/phpunit --testdox

cli: ## Run the interactive CLI demo (order state machine)
	docker compose run --rm web php bin/cli.php

cli-leads: ## Run the CLI demo with the lead state machine
	docker compose run --rm web php bin/cli.php --config config/lead-state-machine.json

diagram: ## Print the order state machine Mermaid diagram
	docker compose run --rm web php -r "require 'vendor/autoload.php'; echo (new Tlab\StateMachine\StateMachineRunner((new Tlab\StateMachine\Reader\DefinitionReader())->readFile('config/order-state-machine.json')))->generateMarkdownDiagram();"

diagram-leads: ## Print the lead state machine Mermaid diagram
	docker compose run --rm web php -r "require 'vendor/autoload.php'; echo (new Tlab\StateMachine\StateMachineRunner((new Tlab\StateMachine\Reader\DefinitionReader())->readFile('config/lead-state-machine.json')))->generateMarkdownDiagram();"

install: ## Install dependencies into ./vendor (via Docker)
	docker compose run --rm web composer install

shell: ## Open a shell in the web container
	docker compose run --rm web bash

clean: ## Remove containers and SQLite data
	docker compose down
	docker compose run --rm web rm -rf data

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

.DEFAULT_GOAL := help
