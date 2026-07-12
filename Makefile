SHELL := /bin/zsh

.PHONY: bootstrap-local run-api run-web run-intelligence api-test compose-up compose-down

bootstrap-local:
	./scripts/bootstrap-local.sh

run-api:
	./scripts/run-api.sh

run-web:
	./scripts/run-web.sh

run-intelligence:
	./scripts/run-intelligence.sh

api-test:
	cd apps/api && php artisan test

compose-up:
	docker compose up --build

compose-down:
	docker compose down
