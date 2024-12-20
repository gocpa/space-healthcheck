<?php

use GoCPA\SpaceHealthcheck\Git;
use Illuminate\Support\Facades\Config;
use Mockery\MockInterface;

use function Pest\Laravel\getJson;

it('has result with correct secretKey', function () {
    $gitInfo = [
        'branchName' => 'master',
        'hash' => 'ac94034',
        'date' => time(),
    ];

    // Мокаем класс гита
    $this->mock(Git::class, function (MockInterface $mock) {
        $mock->shouldReceive('run')->once()->andReturn([
            'branchName' => 'main',
            'tag' => 'v1.0.0',
            'hash' => '9869cc2',
            'date' => time(),
        ]);
    });

    Config::set('space-healthcheck.secretKey', 'mitrofan');
    expect(getJson('/space/check', ['x-space-secret-key' => 'mitrofan']))->assertOk();
});

it('has result with incorrect secretKey', function () {
    Config::set('space-healthcheck.secretKey', 'mitrofan');
    expect(getJson('/space/check', ['x-space-secret-key' => 'invalid-secret-key']))->assertNotFound();
});

it('has result with empty secretKey', function () {
    Config::set('space-healthcheck.secretKey', 'mitrofan');
    expect(getJson('/space/check'))->assertNotFound();
});

it('has no result without secretKey', function () {
    expect(getJson('/space/check')->assertForbidden());
});
