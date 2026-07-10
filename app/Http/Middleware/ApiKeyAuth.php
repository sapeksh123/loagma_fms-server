<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        // Keep disabled by default to avoid breaking current clients.
        if (!filter_var(env('REQUIRE_API_KEY', false), FILTER_VALIDATE_BOOL)) {
            return $next($request);
        }

        $configuredKey = (string) env('API_KEY', '');
        if ($configuredKey === '') {
            return response()->json([
                'status' => false,
                'message' => 'API key auth is enabled but API_KEY is not configured.',
            ], 500);
        }

        $providedKey = (string) $request->header('X-API-Key', '');
        if (!hash_equals($configuredKey, $providedKey)) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        return $next($request);
    }
}
