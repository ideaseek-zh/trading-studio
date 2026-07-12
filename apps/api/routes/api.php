<?php

use App\Http\Controllers\Api\V1\EventController;
use App\Http\Controllers\Api\V1\EventChainController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\IndexController;
use App\Http\Controllers\Api\V1\NewsController;
use App\Http\Controllers\Api\V1\SecurityController;
use App\Http\Controllers\Api\V1\SignalController;
use App\Http\Controllers\Api\V1\SignalSubscriptionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class);
    Route::get('/securities', [SecurityController::class, 'index']);
    Route::get('/securities/search', [SecurityController::class, 'search']);
    Route::get('/securities/{security:canonical_symbol}', [SecurityController::class, 'show']);
    Route::get('/securities/{security:canonical_symbol}/quote', [SecurityController::class, 'quote']);
    Route::get('/securities/{security:canonical_symbol}/daily-bars', [SecurityController::class, 'dailyBars']);
    Route::get('/indices', [IndexController::class, 'index']);
    Route::get('/indices/{index:code}/daily-bars', [IndexController::class, 'dailyBars']);
    Route::get('/news', [NewsController::class, 'index']);
    Route::get('/news/{article}', [NewsController::class, 'show']);
    Route::get('/events', [EventController::class, 'index']);
    Route::get('/events/{event}', [EventController::class, 'show']);
    Route::get('/event-chains', [EventChainController::class, 'index']);
    Route::get('/event-chains/{chain}', [EventChainController::class, 'show']);
    Route::get('/signals', [SignalController::class, 'index']);
    Route::get('/signals/dashboard', [SignalController::class, 'dashboard']);
    Route::get('/signals/{signal}', [SignalController::class, 'show']);
    Route::get('/signal-subscriptions', [SignalSubscriptionController::class, 'index']);
    Route::post('/signal-subscriptions', [SignalSubscriptionController::class, 'store']);
    Route::get('/signal-subscriptions/{subscription}', [SignalSubscriptionController::class, 'show']);
    Route::patch('/signal-subscriptions/{subscription}', [SignalSubscriptionController::class, 'update']);
});
