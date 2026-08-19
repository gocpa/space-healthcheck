<?php

declare(strict_types=1);

use GoCPA\SpaceHealthcheck\Commands\SpaceSendEnvironmentCommand;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

const WEBHOOK_URL = 'https://gocpa.space/api/webhooks/project/environments/update';

beforeEach(function () {
    Config::set('space-healthcheck.secretKey', 'mitrofan');
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
            ->toBe(['app', 'mail', 'database', 'queue', 'space-healthcheck', 'cloud']);

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
