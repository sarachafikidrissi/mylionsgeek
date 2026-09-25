<?php

use App\Http\Controllers\API\MobileProjectMutationController;
use App\Http\Controllers\API\ProjectController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/projects', [ProjectController::class, 'index']);
    Route::post('/projects', [MobileProjectMutationController::class, 'store']);
    Route::get('/projects/{id}', [ProjectController::class, 'show']);
    Route::post('/projects/{id}', [MobileProjectMutationController::class, 'update']);
    Route::delete('/projects/{id}', [MobileProjectMutationController::class, 'destroy']);

    Route::post('/projects/{id}/members', [MobileProjectMutationController::class, 'addMember']);
    Route::post('/projects/{id}/invite', [MobileProjectMutationController::class, 'invite']);
    Route::delete('/projects/{id}/members/{userId}', [MobileProjectMutationController::class, 'removeMember']);
    Route::put('/projects/{id}/members/{userId}', [MobileProjectMutationController::class, 'updateMemberRole']);

    Route::post('/projects/{id}/tasks', [MobileProjectMutationController::class, 'storeTask']);
    Route::post('/projects/{projectId}/tasks/{taskId}', [MobileProjectMutationController::class, 'updateTask']);
    Route::post('/projects/{projectId}/tasks/{taskId}/status', [ProjectController::class, 'updateTaskStatus']);
    Route::delete('/projects/{projectId}/tasks/{taskId}', [MobileProjectMutationController::class, 'destroyTask']);

    Route::post('/projects/{id}/notes', [MobileProjectMutationController::class, 'storeNote']);
    Route::post('/projects/{id}/notes/{noteId}', [MobileProjectMutationController::class, 'updateNote']);
    Route::delete('/projects/{id}/notes/{noteId}', [MobileProjectMutationController::class, 'destroyNote']);

    Route::post('/projects/{id}/attachments', [MobileProjectMutationController::class, 'storeAttachment']);
    Route::delete('/projects/{id}/attachments/{attachmentId}', [MobileProjectMutationController::class, 'destroyAttachment']);

    Route::get('/projects/{id}/messages', [ProjectController::class, 'messages']);
    Route::post('/projects/{id}/messages', [ProjectController::class, 'sendMessage']);
});
