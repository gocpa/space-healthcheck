<?php

declare(strict_types=1);

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
