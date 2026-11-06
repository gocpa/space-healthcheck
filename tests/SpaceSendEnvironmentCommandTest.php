<?php

declare(strict_types=1);

use GoCPA\SpaceHealthcheck\Commands\SpaceSendEnvironmentCommand;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;

const WEBHOOK_URL = 'https://gocpa.space/api/webhooks/project/environments/update';

beforeEach(function () {
    Config::set('space-healthcheck.secretKey', 'mitrofan');
    // Паузы между повторами (2 с) в тестах не ждём.
    Sleep::fake();
});

/**
 * Http::fake() накапливает стабы, и срабатывает первый подходящий,
 * поэтому фейк регистрируем внутри теста, а не в beforeEach.
 */
function fakeWebhook(int $status = 200): void
{
    Http::fake([WEBHOOK_URL => Http::response($status === 200 ? ['ok' => true] : 'nope', $status)]);
}

it('отправляет вебхук с секретом в заголовке', function () {
    fakeWebhook();

    $this->artisan('gocpaspace:send-environment')->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->url() === WEBHOOK_URL
        && $request->method() === 'POST'
        && $request->header('x-space-secret-key') === ['mitrofan']);
});

it('заполняет параметры подключения к базе данных', function () {
    fakeWebhook();
    Config::set('database.default', 'mysql');
    Config::set('database.connections.mysql', [
        'driver' => 'mysql',
        'host' => 'db.example.com',
        'port' => '3306',
        'database' => 'forge',
        'username' => 'forge_user',
    ]);

    $this->artisan('gocpaspace:send-environment')->assertSuccessful();

    Http::assertSent(function (Request $request) {
        expect($request['database'])->toMatchArray([
            'type' => 'mysql',
            'database' => [
                'url' => '',
                'host' => 'db.example.com',
                'port' => '3306',
                'database' => 'forge',
                'username' => 'forge_user',
            ],
        ]);

        return true;
    });
});

it('не падает на числовом порте почты', function () {
    fakeWebhook();
    // В стоковом config/mail.php дефолт — env('MAIL_PORT', 2525), то есть int.
    Config::set('mail.mailers.smtp.port', 2525);

    $this->artisan('gocpaspace:send-environment')->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request['mail']['port'] === '2525');
});

it('отдаёт все секции payload', function () {
    fakeWebhook();

    $this->artisan('gocpaspace:send-environment')->assertSuccessful();

    Http::assertSent(function (Request $request) {
        expect(array_keys((array) $request->data()))
            ->toBe(['app', 'mail', 'database', 'queue', 'space-healthcheck', 'cloud', 'git', 'deploy', 'migrations']);

        return true;
    });
});

it('возвращает ошибку и ничего не отправляет без секрета', function () {
    fakeWebhook();
    Config::set('space-healthcheck.secretKey', null);

    $this->artisan('gocpaspace:send-environment')->assertFailed();

    Http::assertNothingSent();
});

it('возвращает ошибку, если вебхук ответил ошибкой', function () {
    fakeWebhook(500);

    $this->artisan('gocpaspace:send-environment')->assertFailed();

    // 5xx повторяется, но число попыток ограничено.
    Http::assertSentCount(3);
});

it('сливает конфиг horizon с настройками окружения', function () {
    fakeWebhook();
    Config::set('app.env', 'production');
    Config::set('horizon.prefix', 'horizon:');
    Config::set('horizon.defaults', [
        'supervisor-1' => ['connection' => 'redis', 'queue' => ['default'], 'maxProcesses' => 1],
    ]);
    Config::set('horizon.environments', [
        'production' => ['supervisor-1' => ['maxProcesses' => 10]],
        'local' => ['supervisor-1' => ['maxProcesses' => 2]],
    ]);

    $this->artisan('gocpaspace:send-environment')->assertSuccessful();

    Http::assertSent(function (Request $request) {
        expect($request['queue']['horizon'])->toBe([
            'prefix' => 'horizon:',
            'config' => [
                'supervisor-1' => ['connection' => 'redis', 'queue' => ['default'], 'maxProcesses' => 10],
            ],
        ]);

        return true;
    });
});

it('кладёт ошибку в horizon.th, когда horizon не установлен', function () {
    fakeWebhook();

    $this->artisan('gocpaspace:send-environment')->assertSuccessful();

    Http::assertSent(fn (Request $request) => isset($request['queue']['horizon']['th'])
        && ! isset($request['queue']['horizon']['config']));
});

it('configString отвергает значения, которые не привести к строке', function () {
    Config::set('space-healthcheck.folder', ['не', 'строка']);

    expect(fn () => SpaceSendEnvironmentCommand::configString('space-healthcheck.folder'))
        ->toThrow(InvalidArgumentException::class, 'must be a string, array given');
});

it('configArray отвергает не массив', function () {
    Config::set('space-healthcheck.folder', 'строка');

    expect(fn () => SpaceSendEnvironmentCommand::configArray('space-healthcheck.folder'))
        ->toThrow(InvalidArgumentException::class, 'must be an array, string given');
});

/** Каталог с минимальным .git/: detached HEAD без объектов — бинарник git не нужен. */
function makeDetachedGitDirectory(string $hash): string
{
    $directory = sys_get_temp_dir().'/space-healthcheck-cmd-'.bin2hex(random_bytes(6));
    mkdir($directory.'/.git', 0o777, true);
    file_put_contents($directory.'/.git/HEAD', $hash."\n");

    return $directory;
}

/**
 * Своё sqlite-подключение в памяти: в скелете testbench 8 (Laravel 10)
 * по умолчанию стоит mysql, которого в тестах нет.
 */
function useInMemoryDatabase(): void
{
    Config::set('database.connections.space_testing', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    Config::set('database.default', 'space_testing');
}

function createMigrationsTable(string $table = 'migrations'): void
{
    useInMemoryDatabase();
    Schema::create($table, function (Blueprint $blueprint) {
        $blueprint->increments('id');
        $blueprint->string('migration');
        $blueprint->integer('batch');
    });
}

/** @return array<string, mixed> */
function sentPayload(): array
{
    $recorded = Http::recorded();
    expect($recorded)->toHaveCount(1);

    return json_decode($recorded[0][0]->body(), true, 512, JSON_THROW_ON_ERROR);
}

it('не меняет ключи старых секций', function () {
    fakeWebhook();

    $this->artisan('gocpaspace:send-environment')->assertSuccessful();

    $payload = sentPayload();
    expect(array_keys($payload['app']))->toBe(['name', 'env', 'url', 'timezone'])
        ->and(array_keys($payload['mail']))->toBe(['mailer', 'host', 'port'])
        ->and(array_keys($payload['database']))->toBe(['type', 'database', 'redis'])
        ->and(array_keys($payload['database']['redis']))->toBe(['client', 'host', 'port'])
        ->and(array_keys($payload['queue']))->toBe(['driver', 'horizon'])
        ->and(array_keys($payload['space-healthcheck']))->toBe(['projectId', 'folder', 'webserverExtPort'])
        ->and(array_keys($payload['cloud']))->toBe(['GOCPA_PROJECT', 'PROJECT_NAME']);
});

it('кладёт в тело секцию git', function () {
    fakeWebhook();
    $hash = str_repeat('ab', 20);
    $directory = makeDetachedGitDirectory($hash);
    app()->setBasePath($directory);

    try {
        $this->artisan('gocpaspace:send-environment')->assertSuccessful();
    } finally {
        @unlink($directory.'/.git/HEAD');
        @rmdir($directory.'/.git');
        @rmdir($directory);
    }

    expect(sentPayload()['git'])->toBe([
        'branchName' => null,
        'tag' => null,
        'hash' => $hash,
        'date' => null,
    ]);
});

it('кладёт git.exception, если репозитория нет, и всё равно отправляет', function () {
    fakeWebhook();

    // В каталоге testbench нет .git/.
    $this->artisan('gocpaspace:send-environment')->assertSuccessful();

    expect(sentPayload()['git'])->toBe(['exception' => 'git not found']);
});

it('повторяет отправку при 5xx с тем же телом', function () {
    Http::fake([WEBHOOK_URL => Http::sequence()
        ->push('fail', 500)
        ->push('fail', 503)
        ->push(['ok' => true], 200)]);

    $this->artisan('gocpaspace:send-environment')->assertSuccessful();

    $recorded = Http::recorded()->all();
    expect($recorded)->toHaveCount(3);

    $bodies = array_map(fn (array $pair) => $pair[0]->body(), $recorded);
    expect(array_unique($bodies))->toHaveCount(1);

    Sleep::assertSleptTimes(2);
});

it('повторяет отправку при 429', function () {
    Http::fake([WEBHOOK_URL => Http::sequence()
        ->push('slow down', 429)
        ->push(['ok' => true], 200)]);

    $this->artisan('gocpaspace:send-environment')->assertSuccessful();

    Http::assertSentCount(2);
});

it('повторяет отправку при ошибке соединения', function () {
    $attempts = 0;
    Http::fake(function () use (&$attempts) {
        if (++$attempts < 3) {
            throw new ConnectionException('cURL error 7: Failed to connect');
        }

        return Http::response(['ok' => true]);
    });

    $this->artisan('gocpaspace:send-environment')->assertSuccessful();

    expect($attempts)->toBe(3);
});

it('не повторяет отправку при 404', function () {
    fakeWebhook(404);

    $this->artisan('gocpaspace:send-environment')->assertFailed();

    Http::assertSentCount(1);
});

it('шлёт на адрес вебхука из конфига', function () {
    Http::fake(['http://portal.test/*' => Http::response(['ok' => true])]);
    Config::set('space-healthcheck.webhookUrl', 'http://portal.test/api/webhooks/project/environments/update');

    $this->artisan('gocpaspace:send-environment')->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->url() === 'http://portal.test/api/webhooks/project/environments/update');
});

it('по умолчанию шлёт на прежний адрес', function () {
    fakeWebhook();

    expect(config('space-healthcheck.webhookUrl'))->toBe(WEBHOOK_URL)
        ->and(SpaceSendEnvironmentCommand::DEFAULT_WEBHOOK_URL)->toBe(WEBHOOK_URL);

    // Конфиг, опубликованный до появления ключа, не ломает отправку.
    Config::set('space-healthcheck.webhookUrl', null);

    $this->artisan('gocpaspace:send-environment')->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->url() === WEBHOOK_URL);
});

it('по умолчанию сообщает о завершённом деплое', function () {
    fakeWebhook();

    $this->artisan('gocpaspace:send-environment')->assertSuccessful();

    expect(sentPayload()['deploy'])->toMatchArray([
        'event' => 'finished',
        'id' => null,
        'by' => null,
        'message' => null,
        'maintenance' => false,
    ]);
});

it('отвергает неизвестный этап и ничего не отправляет', function (string $event) {
    fakeWebhook();

    $this->artisan('gocpaspace:send-environment', ['--event' => $event])
        ->expectsOutputToContain('started, finished, failed')
        ->assertFailed();

    Http::assertNothingSent();
})->with(['deployed', '']);

it('передаёт этап, идентификатор, инициатора и сообщение', function () {
    fakeWebhook();

    $this->artisan('gocpaspace:send-environment', [
        '--event' => 'failed',
        '--deploy-id' => '2026-11-06T12:00:00Z',
        '--by' => 'ansible-alfa',
        '--message' => 'Шаг «migrate» упал',
    ])->assertSuccessful();

    expect(sentPayload()['deploy'])->toMatchArray([
        'event' => 'failed',
        'id' => '2026-11-06T12:00:00Z',
        'by' => 'ansible-alfa',
        'message' => 'Шаг «migrate» упал',
    ]);
});

it('обрезает сообщение до 2000 символов', function () {
    fakeWebhook();

    $this->artisan('gocpaspace:send-environment', [
        '--event' => 'failed',
        '--message' => str_repeat('ж', 2500),
    ])->assertSuccessful();

    expect(sentPayload()['deploy']['message'])->toBe(str_repeat('ж', 2000));
});

it('сообщает о режиме обслуживания', function () {
    fakeWebhook();
    app()->maintenanceMode()->activate([]);

    try {
        $this->artisan('gocpaspace:send-environment')->assertSuccessful();
    } finally {
        app()->maintenanceMode()->deactivate();
    }

    expect(sentPayload()['deploy']['maintenance'])->toBeTrue();
});

it('отдаёт миграции последнего batch', function (mixed $migrationsConfig, string $table) {
    fakeWebhook();
    Config::set('database.migrations', $migrationsConfig);
    createMigrationsTable($table);
    DB::table($table)->insert([
        ['migration' => '2026_10_01_000000_create_x', 'batch' => 41],
        ['migration' => '2026_11_05_120000_add_x_to_y', 'batch' => 42],
        ['migration' => '2026_11_05_130000_add_z_to_y', 'batch' => 42],
    ]);

    $this->artisan('gocpaspace:send-environment')->assertSuccessful();

    expect(sentPayload()['migrations'])->toBe([
        'batch' => 42,
        'items' => ['2026_11_05_120000_add_x_to_y', '2026_11_05_130000_add_z_to_y'],
    ]);
})->with([
    'Laravel 10: строка' => ['deploy_migrations', 'deploy_migrations'],
    'Laravel 11+: массив' => [['table' => 'deploy_migrations', 'update_date_on_publish' => true], 'deploy_migrations'],
    'по умолчанию' => [null, 'migrations'],
]);

it('ограничивает список миграций сотней записей', function () {
    fakeWebhook();
    createMigrationsTable();
    DB::table('migrations')->insert(array_map(
        fn (int $i) => ['migration' => sprintf('2026_11_05_%06d_m', $i), 'batch' => 1],
        range(1, 150),
    ));

    $this->artisan('gocpaspace:send-environment')->assertSuccessful();

    $migrations = sentPayload()['migrations'];
    expect($migrations['batch'])->toBe(1)
        ->and($migrations['items'])->toHaveCount(100);
});

it('отдаёт пустой список, если миграций ещё не было', function () {
    fakeWebhook();
    createMigrationsTable();

    $this->artisan('gocpaspace:send-environment')->assertSuccessful();

    expect(sentPayload()['migrations'])->toBe(['batch' => null, 'items' => []]);
});

it('кладёт migrations.exception, если таблицы нет, и всё равно отправляет', function () {
    fakeWebhook();
    useInMemoryDatabase();

    $this->artisan('gocpaspace:send-environment')->assertSuccessful();

    $migrations = sentPayload()['migrations'];
    expect($migrations)->toHaveKeys(['exception'])
        ->and($migrations)->not->toHaveKey('items')
        ->and($migrations['exception'])->toContain('no such table: migrations');
});
