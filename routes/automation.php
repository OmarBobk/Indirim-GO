<?php

declare(strict_types=1);

use App\Http\Controllers\FulfillmentAutomationArtifactController;
use App\Http\Controllers\FulfillmentAutomationCallbackController;
use App\Http\Middleware\VerifyFulfillmentAutomationArtifactSignature;
use App\Http\Middleware\VerifyFulfillmentAutomationSignature;
use Illuminate\Support\Facades\Route;

Route::prefix('internal/automation')
    ->group(function (): void {
        Route::post('runs/{uuid}/result', [FulfillmentAutomationCallbackController::class, 'result'])
            ->middleware(VerifyFulfillmentAutomationSignature::class)
            ->name('automation.runs.result');

        Route::post('runs/{uuid}/artifacts', [FulfillmentAutomationCallbackController::class, 'artifacts'])
            ->middleware(VerifyFulfillmentAutomationArtifactSignature::class)
            ->name('automation.runs.artifacts');
    });

Route::middleware(['web', 'auth', 'backend'])
    ->prefix('admin/fulfillment-automation')
    ->group(function (): void {
        Route::get('runs/{run}/artifact', [FulfillmentAutomationArtifactController::class, 'show'])
            ->name('admin.fulfillment-automation.artifacts.show');
    });
