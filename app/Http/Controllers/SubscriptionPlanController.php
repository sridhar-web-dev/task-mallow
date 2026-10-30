<?php

namespace App\Http\Controllers;

use App\Actions\ChangePlan;
use App\Http\Middleware\AuthenticateMerchant;
use App\Http\Requests\ChangePlanRequest;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;

class SubscriptionPlanController extends Controller
{
    public function __invoke(ChangePlanRequest $request, int $subscription, ChangePlan $changePlan): JsonResponse
    {
        // Scoped to the caller, so another merchant's subscription is a plain 404.
        $subscription = Subscription::where('merchant_id', $request->attributes->get(AuthenticateMerchant::REQUEST_ATTRIBUTE))
            ->findOrFail($subscription);

        $segment = $changePlan->handle($subscription, $request->integer('plan_id'), $request->effectiveOn());

        return response()->json([
            'subscription_id' => $subscription->id,
            'plan_id' => $segment->plan_id,
            'effective_on' => $segment->starts_on->toDateString(),
        ]);
    }
}
