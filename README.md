# gocpa/space-healthcheck

[![Latest Version on Packagist](https://img.shields.io/packagist/v/gocpa/space-healthcheck.svg?style=flat-square)](https://packagist.org/packages/gocpa/space-healthcheck)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/gocpa/space-healthcheck/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/gocpa/space-healthcheck/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/gocpa/space-healthcheck.svg?style=flat-square)](https://packagist.org/packages/gocpa/space-healthcheck)

Пакет для мониторинга проектов GoCPA

## Установка

```shell
composer require gocpa/space-healthcheck
```

После установки добавьте конфигурацию в файл .env

```ini
# Токен для интеграции с gocpa.space
GOCPASPACE_HEALTHCHECK_SECRET=example_secret

# Идентификатор проекта на gocpa.space
GOCPASPACE_HEALTHCHECK_PROJECT_ID=123

# Папка с проектом на сервере
GOCPASPACE_HEALTHCHECK_FOLDER=/var/www/projectfolder

# Если проект в докере - укажите тут внешний порт
WEBSERVER_EXT_PORT=8081
# или APP_PORT=8081
```

# CI/CD
Добавьте выполнение команды `php artisan gocpaspace:send-environment` после выполненного деплоя для обновления информации о стенде

# Проверка

```shell
source .env
curl "${APP_URL}/space/check" --header "accept: application/json" --header "x-space-secret-key: ${GOCPASPACE_HEALTHCHECK_SECRET}"
php artisan gocpaspace:send-environment
```
