# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Что это

`gocpa/space-healthcheck` — проприетарный Laravel-пакет (не приложение), который отдаёт состояние стенда в сервис мониторинга gocpa.space. Комментарии, сообщения команд и описания коммитов в этом репозитории — на русском.

Поддерживаемая матрица: PHP 8.1–8.5 × Laravel 10–13. Любое изменение должно работать во всём диапазоне — CI гоняет полный крест (см. `.github/workflows/run-tests.yml`). Это причина, по которой в коде не используются новые хелперы фреймворка: например, вместо `Config::string()` (Laravel 11+) в `SpaceSendEnvironmentCommand` есть собственные `configString()` / `configArray()`.

## Команды

```shell
composer test                      # pest (весь набор)
vendor/bin/pest tests/PackageTest.php          # один файл
vendor/bin/pest --filter "incorrect secretKey" # один тест по имени
composer test-coverage
composer analyse                   # phpstan, level 9, src + config
composer format                    # pint (дефолтный laravel-пресет, конфига нет)
composer lint                      # pint + phpstan разом
composer serve                     # поднять workbench-приложение (testbench serve)
```

Тесты запускаются через Orchestra Testbench (`tests/TestCase.php` регистрирует `SpaceHealthcheckServiceProvider`); отдельного Laravel-приложения в репозитории нет. Каталоги `workbench/` и `build/` — генерируемые.

PHPStan на level 9 с `checkOctaneCompatibility` и `checkModelProperties`. `phpstan-baseline.neon` намеренно пуст — новые ошибки нужно чинить, а не добавлять в baseline.

## Архитектура

Две независимые ветки функциональности, обе завязаны на один секрет `GOCPASPACE_HEALTHCHECK_SECRET`:

1. **Pull: `GET /space/check`** (`routes/web.php` → `SpaceHealthCheckController`). gocpa.space опрашивает стенд. `EnsureSecretKeyIsValid` отдаёт 403 при отсутствующем секрете в конфиге и 404 (не 401/403) при неверном заголовке `x-space-secret-key` — маскировка эндпоинта, тесты это фиксируют.
2. **Push: `php artisan gocpaspace:send-environment`** (`SpaceSendEnvironmentCommand`). Запускается после деплоя, POST-ит снимок конфигурации на `https://gocpa.space/api/webhooks/project/environments/update`. Секрет уходит тем же заголовком.

Регистрация всего этого — через `spatie/laravel-package-tools` в `SpaceHealthcheckServiceProvider::configurePackage()`: конфиг, команда и роут подключаются декларативно, `hasRoute('web')` подхватывает `routes/web.php`.

### Ключевые решения

- **`Git.php` читает `.git/` файлами, не шеллом.** Так работает там, где `exec()` запрещён, а бинарника git нет в контейнере — это зафиксировано arch-тестом «пакет не выполняет внешних команд». Разбираются все раскладки, которые встречаются на стендах:
  - `HEAD` — любая ссылка из `refs/` (не только ветка) либо готовый хеш в detached HEAD, куда git переводит проект при деплое по тегу или SHA;
  - тег, указывающий на текущий коммит, — из `refs/tags/` (в том числе вложенных) и `packed-refs`, аннотированный разворачивается до коммита; при нескольких тегах берётся последний в натуральной сортировке;
  - ветка — `refs/heads/<branch>`, при отсутствии loose-ссылки фолбэк на `packed-refs` (после `git gc`);
  - объект коммита ищется во всех каталогах объектов — своём и подключённых через `objects/info/alternates` (`clone --shared`, `--reference`), — сначала loose из `objects/xx/yyy`, иначе из packfile: разбор `.idx` версии 2 (включая 8-байтные смещения для паков больше 2 ГБ) и разворачивание дельт `OFS_DELTA`/`REF_DELTA`. Без этого дата коммита была бы `null` на любом свежем `git clone`, где loose-объектов нет вообще;
  - `.git` как файл (`gitdir: …`) в worktree и submodule, с учётом `commondir`.

  Конструктор бросает исключение, если `.git/` отсутствует; контроллер это ловит и кладёт `git.exception` в ответ. Всё остальное деградирует до `null` в отдельном поле, а не роняет разбор.
- **Всё завёрнуто в try/catch — эндпоинт не должен падать.** Контроллер ловит `\Throwable` на верхнем уровне и отдаёт 200 с ключом `exception`. Мониторинг важнее корректной ошибки.
- **`spatie/laravel-health` — опциональная зависимость** (`suggest`, dev-only). `getHealthData()` резолвит `ResultStore` из контейнера; при отсутствии пакета исключение проглатывается верхним catch.
- **Список пакетов в `getComposerInfo()` захардкожен** — версии тянутся через `Composer\InstalledVersions`, отсутствующие дают `null`. Добавление пакета в мониторинг = правка этого массива.
- **Ответ webhook'а собирается по секциям** (`getAppInfo`, `getMailInfo`, `getDatabaseInfo`, `getQueueInfo`, `getHealthcheckInfo`, `getCloudInfo`); секция horizon дополнительно обёрнута в try/catch и кладёт текст ошибки в `horizon.th`. Форму payload'а потребляет внешний сервис — менять ключи только согласованно с ним.

## CI

Помимо тестов и PHPStan, workflow `run-tests.yml` содержит job `install`: он создаёт свежий Laravel через `composer create-project`, ставит пакет с текущей ветки (`dev-<branch>`), инициализирует git-репозиторий и реально дёргает `/space/check` через HTTP. Это единственное место, где `Git.php` проверяется на настоящем `.git/`. Пуш ветки с изменениями в `**.php` запускает обе матрицы.

Pint прогоняется автоматически (`fix-php-code-style-issues.yml`) и коммитит правки в ветку — форматирование чинить руками не обязательно, но `composer format` перед пушем экономит круг CI.
