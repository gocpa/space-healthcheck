<?php

use Illuminate\Support\Facades\Config;
use function Pest\Laravel\getJson;

it('has result with correct secretKey', function () {
    Config::set('space-healthcheck.secretKey', $secretKey = 'mitrofan');
    expect(getJson('/space/check?secretKey='.$secretKey, []))->assertOk();
});

it('has result with incorrect secretKey', function () {
    Config::set('space-healthcheck.secretKey', 'mitrofan');
    expect(getJson('/space/check?secretKey=invalid-secret-key', []))->assertNotFound();
});

it('has result with empty secretKey', function () {
    Config::set('space-healthcheck.secretKey', 'mitrofan');
    expect(getJson('/space/check', []))->assertNotFound();
});

it('has no result without secretKey', function () {
    expect(getJson('/space/check', ['accept' => 'text/json'])->assertForbidden());
});
