<?php

namespace App\Http\Controllers;

use App\Http\Middleware\AuthenticateMerchant;
use App\Models\Merchant;
use App\Services\Dashboard\MerchantDashboard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MerchantDashboardController extends Controller
{
    public function __invoke(Request $request, int $merchant, MerchantDashboard $dashboard): JsonResponse
    {
        // A 404 rather than a 403, so a caller cannot probe which ids exist.
        abort_unless(
            $merchant === $request->attributes->get(AuthenticateMerchant::REQUEST_ATTRIBUTE),
            Response::HTTP_NOT_FOUND,
        );

        return response()->json($dashboard->forMerchant(Merchant::findOrFail($merchant)));
    }
}
