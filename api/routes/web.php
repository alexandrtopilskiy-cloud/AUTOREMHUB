<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\EdoPreviewController;

if (app()->environment('local')) {
    Route::prefix('edo/preview')->group(function () {
        // Просмотр PDF Заказ-наряда
        Route::get('work-order/{number}', [EdoPreviewController::class, 'workOrder'])->name('edo.preview.work_order');

        // Просмотр PDF Отчета агента (если нужно)
        Route::get('agent-report/{number}', [EdoPreviewController::class, 'agentReport'])->name('edo.preview.agent_report');
    });
}