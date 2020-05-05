SHELL := /bin/bash

start:
    symfony server:start -d
    docker-compose up -d
    symfony open:local

tests:
    symfony console doctrine:fixtures:load -n
    ./vendor/bin/phpunit
.PHONY: tests