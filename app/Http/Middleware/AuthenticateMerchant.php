<?php

namespace App\Http\Middleware;

use App\Services\TenantDirectory;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMerchant
{
    public const REQUEST_ATTRIBUTE = 'merchant_id';

    public function __construct(private TenantDirectory $directory) {}

    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = (string) $request->header('X-Api-Key');

        $merchantId = $apiKey === '' ? null : $this->directory->merchantIdForApiKey($apiKey);

        if ($merchantId === null) {
            return response()->json(['message' => 'Invalid API key.'], Response::HTTP_UNAUTHORIZED);
        }

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $merchantId);

        return $next($request);
    }
}
