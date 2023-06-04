<?php

declare(strict_types=1);

namespace GoCPA\SpaceHealthcheck\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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
        $secret = config('space-healthcheck.secretKey');
        abort_if(empty($secret), 403, 'space secretKey is empty');
        abort_if($request->input('secretKey') !== $secret, 404);

        return $next($request);
    }
}
