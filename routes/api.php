<?php

use App\Http\Controllers\MerchantDashboardController;
use App\Http\Controllers\SubscriptionPlanController;
use App\Http\Controllers\UsageController;
use Illuminate\Support\Facades\Route;

Route::middleware('merchant.auth')->group(function () {
    Route::post('/usage', UsageController::class)->middleware('throttle:usage');

    Route::post('/subscriptions/{subscription}/change-plan', SubscriptionPlanController::class)
        ->whereNumber('subscription');

    Route::get('/merchants/{merchant}/dashboard', MerchantDashboardController::class)
        ->whereNumber('merchant');
});
