# Changelog

All notable changes to `space-healthcheck` will be documented in this file.

## v2.3.0 - 2026-11-06

### Новое

`gocpaspace:send-environment` отдаёт данные для истории деплоев и релиз-нот на портале.

- **Секция `git`** — ветка, тег, хеш и дата коммита в той же форме, что в `/space/check`. Без `.git/` — `git.exception`, отправка не падает.
- **Секция `deploy`** — этап, идентификатор запуска, инициатор, сообщение, режим обслуживания и время отправки:
  - `--event=started|finished|failed`, по умолчанию `finished`: прежний вызов без опций значит «деплой завершён». Неизвестное значение — ошибка, запрос не уходит;
  - `--deploy-id=` связывает этапы одного деплоя, `--by=` — кто его запустил (не угадывается, без опции — `null`);
  - `--message=` — обычно текст ошибки при `failed`, обрезается до 2000 символов;
  - `maintenance` — стенд остался в `artisan down`.
- **Секция `migrations`** — номер последнего batch и его миграции (не больше 100). Работает с `database.migrations` и строкой (Laravel 10), и массивом (11+); без таблицы — `migrations.exception`.
- **Повтор отправки** — 3 попытки с паузой 2 с при обрыве соединения, 5xx и 429. 404 (неверный секрет) и прочие 4xx не повторяются. Код выхода прежний: окончательная неудача — `1`.
- **`GOCPASPACE_HEALTHCHECK_WEBHOOK_URL`** — адрес вебхука, чтобы проверить отправку на локальном портале. По умолчанию прежний.

В README — опции команды и пример для ansible: `started` в начале, `finished` в конце, `failed` в `rescue`.

> ⚠️ Новые секции `git`, `deploy` и `migrations` в теле вебхука — на стороне gocpa.space их нужно научиться читать. Старые секции и их ключи не изменились.

### Внутреннее

- Разрешён Pest 5 (фактически встаёт только на Laravel 13 × PHP 8.4/8.5), ограничение PHP упрощено до `^8.1`.
- CI: в composer больше не уходит ограничение вида `13.*.*`.
- CHANGELOG пишется в PR до тега, а не коммитится в `main` после релиза: так запись попадает в сам тег. Workflow `update-changelog.yml` удалён.

## v2.2.0 - 2026-08-19

### Исправления

- **Ответ `/space/check` больше не теряет поля.** Если `spatie/laravel-health` не установлен, срабатывал верхний `catch` и из ответа пропадали `environment`, `name`, `env` и `debug` — в том числе `debug`, по которому заводят алерты.
- **Данные БД в вебхуке заполняются.** В ключах конфига была потеряна точка, все пять полей уходили пустыми строками.
- **`gocpaspace:send-environment` не падает на числовом `MAIL_PORT`.** В стоковом `config/mail.php` порт — `int`, и без переменной в `.env` вебхук не отправлялся вообще.
- **Команда больше не рапортует об успехе, ничего не отправив** — без секрета возвращает код ошибки.

### Безопасность

- Убран `withoutVerifying()`: секрет и сводка по инфраструктуре уходили по TLS без проверки сертификата. Добавлен таймаут.
- Сверка секрета через `hash_equals` — за постоянное время.

### Чтение git

Работает на раскладках, которые реально встречаются на деплое: packfile (после `git clone` дата коммита была `null`), дельта-объекты `OFS_DELTA` и `REF_DELTA`, `packed-refs`, detached HEAD, worktree и submodule, alternates при `clone --shared`, репозитории с SHA-256. Сверено с `git log` на всей истории: 196 коммитов из 196.

#### Новое поле `git.tag`

При деплое по тегу `branchName` пуст, и о стенде оставался только хеш. Теперь отдаётся имя тега, указывающего на текущий коммит.

> ⚠️ Новый ключ в ответе `/space/check` — на стороне gocpa.space его нужно научиться читать.

### Внутреннее

- Тестов 8 → 39, покрытие 24.3% → 89.6%; `SpaceSendEnvironmentCommand` и `Git` до этого не покрывались вовсе.
- Arch-тест запрещает `exec` и подобные в неймспейсе пакета: на стендах они могут быть в `disable_functions`.
- `ext-zlib` объявлен в `suggest`, код проверяет наличие функций — без расширения теряется только `git.date`.
- Контроллер больше не тянет `Illuminate\Foundation`; убраны мёртвые записи из `composer.json`.

### CI

- `install`-джоб проверяет тело ответа, а не просто печатает его.
- Пакет в `install`-джобе ставится **из чекаута**, а не из Packagist: раньше джоб проверял то, что успело проиндексироваться, и падал на любом PR от dependabot, потому что таких веток в Packagist нет вовсе.
- Матрица PHP × Laravel прогоняется на публикации пре-релиза и релиза — до этого тег не проверялся ничем.
- Разрешена установка на ветках Laravel, целиком закрытых security advisory (CVE-2026-48019 исправлен только в 12.60 и 13.10).
- `actions/checkout` обновлён до v7.

## v2.1.3 - 2026-04-07

### What's Changed

* build(deps): bump dependabot/fetch-metadata from 2.3.0 to 2.4.0 by @dependabot[bot] in https://github.com/gocpa/space-healthcheck/pull/31
* build(deps): bump aglipanci/laravel-pint-action from 2.5 to 2.6 by @dependabot[bot] in https://github.com/gocpa/space-healthcheck/pull/33
* build(deps): bump stefanzweifel/git-auto-commit-action from 5 to 6 by @dependabot[bot] in https://github.com/gocpa/space-healthcheck/pull/32
* build(deps): bump dependabot/fetch-metadata from 2.4.0 to 2.5.0 by @dependabot[bot] in https://github.com/gocpa/space-healthcheck/pull/37
* chore: update PHP and Laravel version requirements in composer.json a… by @vaninanton in https://github.com/gocpa/space-healthcheck/pull/42
* build(deps): bump dependabot/fetch-metadata from 2.5.0 to 3.0.0 by @dependabot[bot] in https://github.com/gocpa/space-healthcheck/pull/41
* build(deps): bump ramsey/composer-install from 3 to 4 by @dependabot[bot] in https://github.com/gocpa/space-healthcheck/pull/40
* build(deps): bump fjogeleit/http-request-action from 1 to 2 by @dependabot[bot] in https://github.com/gocpa/space-healthcheck/pull/38
* build(deps): bump actions/checkout from 4 to 6 by @dependabot[bot] in https://github.com/gocpa/space-healthcheck/pull/36
* build(deps): bump stefanzweifel/git-auto-commit-action from 6 to 7 by @dependabot[bot] in https://github.com/gocpa/space-healthcheck/pull/35

**Full Changelog**: https://github.com/gocpa/space-healthcheck/compare/v2.1.2...v2.1.3

## v2.1.2 - 2025-04-14

**Full Changelog**: https://github.com/gocpa/space-healthcheck/compare/v2.1.0...v2.1.2

## v2.1.1 - 2025-04-11

### What's Changed

* refactor: заменить вызовы config на статические методы для получения конфигурации - совместимость со старыми версиями laravel by @vaninanton

**Full Changelog**: https://github.com/gocpa/space-healthcheck/compare/v2.0.0...v2.1.1

## v2.1.0 - 2025-04-09

### What's Changed

* feat: добавлена команда для отправки данных стенда в gocpa.space  by @vaninanton in https://github.com/gocpa/space-healthcheck/pull/29
* fix: обновлены зависимости by @vaninanton in https://github.com/gocpa/space-healthcheck/pull/30

**Full Changelog**: https://github.com/gocpa/space-healthcheck/compare/v2.0.0...v2.1.0

## v2.0.0 - 2025-03-12

### What's Changed

* Bump dependabot/fetch-metadata from 2.2.0 to 2.3.0 by @dependabot in https://github.com/gocpa/space-healthcheck/pull/25
* Bump aglipanci/laravel-pint-action from 2.4 to 2.5 by @dependabot in https://github.com/gocpa/space-healthcheck/pull/26
* Laravel 12.x Compatibility by @laravel-shift in https://github.com/gocpa/space-healthcheck/pull/27
* v2.0 Compatibility with Laravel 12 by @vaninanton in https://github.com/gocpa/space-healthcheck/pull/28

### New Contributors

* @laravel-shift made their first contribution in https://github.com/gocpa/space-healthcheck/pull/27

**Full Changelog**: https://github.com/gocpa/space-healthcheck/compare/v1.0.17...v2.0.0
