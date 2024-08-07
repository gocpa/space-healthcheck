# gocpa/space-healthcheck

[![Latest Version on Packagist](https://img.shields.io/packagist/v/gocpa/space-healthcheck.svg?style=flat-square)](https://packagist.org/packages/gocpa/space-healthcheck)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/gocpa/space-healthcheck/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/gocpa/space-healthcheck/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/gocpa/space-healthcheck/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/gocpa/space-healthcheck/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/gocpa/space-healthcheck.svg?style=flat-square)](https://packagist.org/packages/gocpa/space-healthcheck)

Пакет для мониторинга проектов GoCPA

## Установка

```shell
composer require gocpa/space-healthcheck
```

После установки добавьте на стенды секретный ключ в файл .env

```ini
GOCPASPACE_HEALTHCHECK_SECRET=секретный_токен
```

# Проверка

```shell
source .env
curl "${APP_URL}/space/check" --header "accept: application/json" --header "x-space-secret-key: ${GOCPASPACE_HEALTHCHECK_SECRET}"
```

