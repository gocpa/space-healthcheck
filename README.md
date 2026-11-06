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

# Необязательно: адрес вебхука, например для проверки на локальном портале
GOCPASPACE_HEALTHCHECK_WEBHOOK_URL=https://gocpa.space/api/webhooks/project/environments/update
```

# CI/CD

Команда `php artisan gocpaspace:send-environment` отправляет в gocpa.space снимок стенда: конфигурацию,
текущий коммит (`git`), этап деплоя (`deploy`) и миграции последнего batch (`migrations`). По этим
вебхукам портал строит историю деплоев и список PR, попавших на стенд.

Без опций команда сообщает о завершённом деплое — её достаточно вызвать последним шагом.

| Опция | Значение |
|---|---|
| `--event=started\|finished\|failed` | Этап деплоя, по умолчанию `finished`. Другое значение — ошибка, ничего не отправляется |
| `--deploy-id=` | Идентификатор запуска, общий для `started`/`finished`/`failed` одного деплоя |
| `--by=` | Кто запустил деплой: логин из CI или `ansible_user_id`. Не задан — `null` |
| `--message=` | Пояснение к этапу, обычно текст ошибки при `failed`; обрезается до 2000 символов |

Тело при любом этапе полное: при `started` в `git` ещё лежит предыдущий коммит.

## Надёжность отправки

- При обрыве соединения, ответах 5xx и 429 запрос повторяется: всего 3 попытки с паузой 2 с.
  Остальные 4xx не повторяются — 404 означает неверный `GOCPASPACE_HEALTHCHECK_SECRET`.
- Если отправить так и не удалось, команда завершается с кодом `1`. Ронять ли из-за этого деплой,
  решает сам деплой: в ansible — `ignore_errors: true` или `failed_when`.

## Пример для ansible

```yaml
- name: Деплой
  vars:
    deploy_id: "{{ ansible_date_time.iso8601 }}"
    deploy_by: "{{ lookup('env', 'GITHUB_ACTOR') | default(ansible_user_id, true) }}"
    space_cmd: >-
      php artisan gocpaspace:send-environment
      --deploy-id={{ deploy_id | quote }} --by={{ deploy_by | quote }}
  block:
    - name: Сообщить gocpa.space о начале деплоя
      ansible.builtin.command: "{{ space_cmd }} --event=started"
      args:
        chdir: "{{ project_path }}"
      ignore_errors: true

    # ... шаги деплоя ...

    - name: Сообщить gocpa.space о завершении деплоя
      ansible.builtin.command: "{{ space_cmd }} --event=finished"
      args:
        chdir: "{{ project_path }}"
      ignore_errors: true
  rescue:
    - name: Сообщить gocpa.space о сбое деплоя
      ansible.builtin.command: >-
        {{ space_cmd }} --event=failed
        --message={{ (ansible_failed_result.msg | default('') ~ ' ' ~ ansible_failed_result.stderr | default('')) | trim | quote }}
      args:
        chdir: "{{ project_path }}"
      ignore_errors: true

    - name: Завершить плейбук с ошибкой
      ansible.builtin.fail:
        msg: "Деплой не удался: {{ ansible_failed_task.name }}"
```

`deploy_id` берётся из фактов, собранных при старте плейбука, поэтому все три вызова получают одно значение.

# Проверка

```shell
source .env
curl "${APP_URL}/space/check" --header "accept: application/json" --header "x-space-secret-key: ${GOCPASPACE_HEALTHCHECK_SECRET}"
php artisan gocpaspace:send-environment
```
