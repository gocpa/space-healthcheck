<?php

use GoCPA\SpaceHealthcheck\Http\Middleware\EnsureSecretKeyIsValid;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

test('middleware пропускает дальше с правильным ключом', function () {
    Config::set('space-healthcheck.secretKey', 'valid-secret-key');

    $request = Request::create(route('space.check'));
    $request->headers->set('x-space-secret-key', 'valid-secret-key');
    $next = fn () => response('correct result');

    $middleware = new EnsureSecretKeyIsValid();
    $response = $middleware->handle($request, $next);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    expect($response->getContent())->toBe('correct result');
});

test('middleware возвращает 404 при неправильном ключе', function () {
    Config::set('space-healthcheck.secretKey', 'valid-secret-key');
    $request = Request::create(route('space.check'));
    $next = fn () => response('correct result');

    try {
        $middleware = new EnsureSecretKeyIsValid();
        $middleware->handle($request, $next);
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $th) {
    }

    expect($th)->toBeInstanceOf(NotFoundHttpException::class);
});

test('middleware возвращает 403 при пустом ключе', function () {
    Config::set('space-healthcheck.secretKey', '');
    $request = Request::create(route('space.check'));
    $next = fn () => response('correct result');

    try {
        $middleware = new EnsureSecretKeyIsValid();
        $middleware->handle($request, $next);
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $th) {
    }

    expect($th)->toBeInstanceOf(HttpException::class);
    expect($th->getStatusCode())->toBe(403);
    expect($th->getMessage())->toBe('No secret key set. Please set GOCPASPACE_HEALTHCHECK_SECRET in .env file.');
});
