<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use LaravelAudit\Http\Controllers\AuditPanelController;

$prefix = (string) config('laravel-audit.dashboard.path', 'audit');
$middleware = config('laravel-audit.dashboard.middleware', ['web']);

Route::middleware($middleware)
    ->prefix($prefix)
    ->name('laravel-audit.')
    ->group(function (): void {
        Route::get('/', [AuditPanelController::class, 'dashboard'])->name('dashboard');
        Route::get('/reports', [AuditPanelController::class, 'index'])->name('reports.index');
        Route::get('/reports/create', [AuditPanelController::class, 'create'])->name('reports.create');
        Route::post('/reports', [AuditPanelController::class, 'store'])->name('reports.store');
        Route::get('/reports/{uuid}', [AuditPanelController::class, 'show'])->name('reports.show');
        Route::post('/reports/{uuid}/confirm-patterns', [AuditPanelController::class, 'confirmPatterns'])->name('reports.confirm-patterns');
        Route::get('/runs', [AuditPanelController::class, 'runsIndex'])->name('runs.index');
        Route::get('/runs/{uuid}', [AuditPanelController::class, 'runShow'])->name('runs.show');
        Route::get('/runs/{uuid}/status', [AuditPanelController::class, 'runStatus'])->name('runs.status');
        Route::post('/runs/{uuid}/kick', [AuditPanelController::class, 'runKick'])->name('runs.kick');
        Route::post('/runs/{uuid}/execute', [AuditPanelController::class, 'runExecute'])->name('runs.execute');
    });
