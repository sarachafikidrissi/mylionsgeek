<?php

use App\Http\Controllers\Admin\StoryReportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'role:admin,super_admin,moderateur,coach,studio_responsable'])
    ->prefix('admin')
    ->group(function () {
        Route::get('/story-reports', [StoryReportController::class, 'index'])->name('admin.story-reports.index');
        Route::get('/story-reports/{report}/media', [StoryReportController::class, 'media'])
            ->whereNumber('report')
            ->name('admin.story-reports.media');
        Route::post('/story-reports/{report}/accept', [StoryReportController::class, 'accept'])
            ->whereNumber('report')
            ->name('admin.story-reports.accept');
        Route::post('/story-reports/{report}/refuse', [StoryReportController::class, 'refuse'])
            ->whereNumber('report')
            ->name('admin.story-reports.refuse');
    });
