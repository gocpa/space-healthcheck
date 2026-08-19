<?php

declare(strict_types=1);

namespace GoCPA\SpaceHealthcheck\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

class EnsureSecretKeyIsValid
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = Config::get('space-healthcheck.secretKey');
        $secret = is_scalar($secret) ? (string) $secret : '';
        abort_if($secret === '', 403, 'No secret key set. Please set GOCPASPACE_HEALTHCHECK_SECRET in .env file.');

        // hash_equals — сравнение за постоянное время: эндпоинт публичный и без throttle.
        $provided = $request->header('x-space-secret-key');
        abort_unless(is_string($provided) && hash_equals($secret, $provided), 404);

        return $next($request);
    }
}
