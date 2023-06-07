<?php

use GoCPA\SpaceHealthcheck\Git;
use Illuminate\Support\Facades\Config;
use Mockery\MockInterface;
use function Pest\Laravel\getJson;

it('has result with correct secretKey', function () {
    // Мокаем класс гита
    $this->mock(Git::class, function (MockInterface $mock) {
        $mock->shouldReceive('getBranchName')->once()->andReturn('master');
        $mock->shouldReceive('getHash')->once()->andReturn(fake()->sha1);
        $mock->shouldReceive('getCommitDate')->once()->andReturn(time());
    });

    Config::set('space-healthcheck.secretKey', $secretKey = 'mitrofan');
    expect(getJson('/space/check?secretKey='.$secretKey))->assertOk();
});

it('has result with incorrect secretKey', function () {
    Config::set('space-healthcheck.secretKey', 'mitrofan');
    expect(getJson('/space/check?secretKey=invalid-secret-key'))->assertNotFound();
});

it('has result with empty secretKey', function () {
    Config::set('space-healthcheck.secretKey', 'mitrofan');
    expect(getJson('/space/check'))->assertNotFound();
});

it('has no result without secretKey', function () {
    expect(getJson('/space/check')->assertForbidden());
});
