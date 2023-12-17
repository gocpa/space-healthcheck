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
     * @param  \Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = Config::get('space-healthcheck.secretKey');
        abort_if(empty($secret), 403, 'No secret key set. Please set GOCPASPACE_HEALTHCHECK_SECRET in .env file.');
        abort_if($request->header('x-space-secret-key') !== $secret, 404);

        return $next($request);
    }
}
