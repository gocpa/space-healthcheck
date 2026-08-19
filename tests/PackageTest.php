<?php

use GoCPA\SpaceHealthcheck\Git;
use Illuminate\Support\Facades\Config;
use Mockery\MockInterface;
use Spatie\Health\ResultStores\ResultStore;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResults;

use function Pest\Laravel\getJson;

beforeEach(function () {
    Config::set('space-healthcheck.secretKey', 'mitrofan');

    // Мокаем класс гита
    $this->mock(Git::class, function (MockInterface $mock) {
        $mock->shouldReceive('run')->andReturn([
            'branchName' => 'main',
            'hash' => '9869cc2',
            'date' => time(),
        ]);
    });
});

it('has result with correct secretKey', function () {
    expect(getJson('/space/check', ['x-space-secret-key' => 'mitrofan']))->assertOk();
});

it('отдаёт все секции ответа', function () {
    $this->mock(ResultStore::class, function (MockInterface $mock) {
        $mock->shouldReceive('latestResults')->andReturnNull();
    });

    getJson('/space/check', ['x-space-secret-key' => 'mitrofan'])
        ->assertOk()
        ->assertJsonStructure(['generatedAt', 'environment', 'name', 'env', 'debug', 'git', 'composer'])
        ->assertJsonMissingPath('exception')
        ->assertJsonPath('git.branchName', 'main')
        ->assertJsonPath('health', null);
});

it('не теряет остальные секции, когда health недоступен', function () {
    // spatie/laravel-health — опциональная зависимость, ResultStore может не резолвиться.
    app()->bind(ResultStore::class, fn () => throw new RuntimeException('health недоступен'));

    getJson('/space/check', ['x-space-secret-key' => 'mitrofan'])
        ->assertOk()
        ->assertJsonStructure(['generatedAt', 'environment', 'name', 'env', 'debug', 'git', 'composer', 'exception'])
        ->assertJsonPath('exception', 'health недоступен')
        ->assertJsonPath('env', config('app.env'));
});

it('кладёт текст ошибки в git.exception, если репозитория нет', function () {
    // Настоящий Git вместо мока: в каталоге testbench нет .git/.
    app()->bind(Git::class, fn () => new Git);

    getJson('/space/check', ['x-space-secret-key' => 'mitrofan'])
        ->assertOk()
        ->assertJsonPath('git.exception', 'git not found')
        // остальные секции при этом на месте
        ->assertJsonStructure(['generatedAt', 'name', 'env', 'debug', 'composer']);
});

it('отдаёт данные health, когда они есть', function () {
    $this->mock(ResultStore::class, function (MockInterface $mock) {
        $results = Mockery::mock(StoredCheckResults::class);
        $results->shouldReceive('toJson')->andReturn('{"finishedAt":1700000000,"checkResults":[]}');
        $mock->shouldReceive('latestResults')->andReturn($results);
    });

    getJson('/space/check', ['x-space-secret-key' => 'mitrofan'])
        ->assertOk()
        ->assertJsonPath('health.finishedAt', 1700000000)
        ->assertJsonPath('health.checkResults', []);
});

it('has result with incorrect secretKey', function () {
    expect(getJson('/space/check', ['x-space-secret-key' => 'invalid-secret-key']))->assertNotFound();
});

it('has result with empty secretKey', function () {
    expect(getJson('/space/check'))->assertNotFound();
});

it('has no result without secretKey', function () {
    Config::set('space-healthcheck.secretKey', null);

    expect(getJson('/space/check')->assertForbidden());
});
