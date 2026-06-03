<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ApiKeyMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $expectedKey = env('API_SECRET_KEY', '');
        $providedKey = $request->header('X-API-Key');

        if (empty($expectedKey)) {
            return $next($request);
        }

        if ($providedKey !== $expectedKey) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
