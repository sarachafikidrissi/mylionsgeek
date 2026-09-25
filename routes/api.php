<?php

use App\Http\Controllers\API\LearningController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\UserController;
use App\Http\Controllers\API\AppVersionController;
use App\Http\Controllers\API\MobileAuthController;
use App\Http\Controllers\PlacesController;
use App\Http\Controllers\API\ReservationController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SendClassController;
use Illuminate\Http\Request;



Route::get('/reservations/{id}', [ReservationController::class, 'show'])
    ->middleware('auth:sanctum');
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/invite-student', [UserController::class, 'inviteStudent'])
    ->middleware('invite.student');

// Mobile authentication endpoints (public, throttled before credential/reset work)
Route::post('/mobile/login', [MobileAuthController::class, 'login'])
    ->middleware('throttle:mobile-login');
Route::post('/mobile/forgot-password', [MobileAuthController::class, 'forgot'])
    ->middleware('throttle:mobile-forgot-password');

// Signed close-friends story media (no Sanctum; signature is the auth).
Route::get('/mobile/stories/{story}/file', [\App\Http\Controllers\API\StoryController::class, 'streamMedia'])
    ->middleware('signed')
    ->name('mobile.stories.file');
Route::get('/mobile/stories/asset', [\App\Http\Controllers\API\StoryController::class, 'streamAsset'])
    ->middleware('signed')
    ->name('mobile.stories.asset');

// Mobile app version check (public — no auth required)
Route::get('/mobile/app-version', [AppVersionController::class, 'show']);

// LionsGeek (lionsgeek.ma) events/info-session proxy for the mobile app.
// Incoming: Sanctum + per-route authorization. Outgoing: server-side LIONSGEEK_MA_API_KEY.
require __DIR__ . '/api/events-info.php';

// lionsgeek.ma → mylionsgeek webhooks (shared LIONSGEEK_MA_API_KEY bearer).
require __DIR__ . '/api/internal.php';

Route::get('/users', [ReservationController::class, 'getUserss'])
    ->middleware('auth:sanctum')
    ->name('admin.api.users');

Route::get('/equipment', [ReservationController::class, 'getEquipment'])
    ->middleware('auth:sanctum')
    ->name('admin.api.equipment');

Route::get('/places', [PlacesController::class, 'getPlacesJson'])
    ->middleware('auth:sanctum')
    ->name('admin.api.places');

Route::post('/reservations/store', [ReservationController::class, 'storemobile'])
    ->middleware('auth:sanctum')
    ->name('reservations.store');

Route::post('/cowork/reserve', [ReservationController::class, 'storeReservationCoworkMobile'])
    ->middleware('auth:sanctum')
    ->name('cowork.reserve');

require __DIR__ . "/api/learning.php";

Route::middleware('auth:sanctum')->prefix('mobile')->group(function () {
    require __DIR__ . '/api/profile.php';
    require __DIR__ . '/api/activity.php';
    require __DIR__ . '/api/posts.php';
    require __DIR__ . '/api/projects.php';
    require __DIR__ . '/api/reservations.php';
    require __DIR__ . '/api/leaderboard.php';
    require __DIR__ . '/api/search.php';
    require __DIR__ . '/api/training.php';
    require __DIR__ . '/api/face-enrollment.php';
    require __DIR__ . '/api/notifications.php';

    Route::post('/password', [MobileAuthController::class, 'updatePassword']);
    Route::post('/logout', [MobileAuthController::class, 'logout']);

    // Push token endpoint
    Route::post('/push-token', [\App\Http\Controllers\API\PushTokenController::class, 'store']);

    // Test push notification endpoints (admin-only debug surface)
    Route::post('/test-push', [\App\Http\Controllers\API\TestPushController::class, 'test'])
        ->middleware('role:admin');
    Route::get('/push-status', [\App\Http\Controllers\API\TestPushController::class, 'status'])
        ->middleware('role:admin');

    // Chat routes
    Route::prefix('chat')->name('chat.')->group(function () {
        Route::get('/', [ChatController::class, 'index'])->name('index');
        Route::get('/following-ids', [ChatController::class, 'getFollowingIds'])->name('following-ids');
        Route::get('/following-users', [ChatController::class, 'getFollowingUsers'])->name('following-users');
        Route::get('/unread-count', [ChatController::class, 'getUnreadCount'])->name('unread-count');
        Route::get('/conversation/{userId}', [ChatController::class, 'getOrCreateConversation'])->name('conversation');
        Route::get('/conversation/{conversationId}/messages', [ChatController::class, 'getMessages'])->name('messages');
        Route::post('/conversation/{conversationId}/send', [ChatController::class, 'sendMessage'])->name('send');
        Route::post('/conversation/{conversationId}/read', [ChatController::class, 'markAsRead'])->name('mark-read');
        Route::put('/message/{messageId}', [ChatController::class, 'updateMessage'])->name('message.update');
        Route::post('/message/{messageId}/react', [ChatController::class, 'toggleReaction'])->name('message.react');
        Route::delete('/message/{messageId}', [ChatController::class, 'deleteMessage'])->name('message.delete');
        Route::get('/message/{messageId}/attachment', [ChatController::class, 'downloadAttachment'])->name('message.attachment');
        Route::delete('/conversation/{conversationId}', [ChatController::class, 'deleteConversation'])->name('conversation.delete');
        Route::get('/user/{userId}/posts', [ChatController::class, 'getUserPosts'])->name('user.posts');
        Route::get('/ably-token', [ChatController::class, 'getAblyToken'])->name('ably-token');
    });

    // Stories routes
    Route::get('/stories', [\App\Http\Controllers\API\StoryController::class, 'index'])->name('stories.index');
    Route::get('/stories/archive', [\App\Http\Controllers\API\StoryController::class, 'archive'])->name('stories.archive');
    Route::post('/stories', [\App\Http\Controllers\API\StoryController::class, 'store'])->name('stories.store');
    Route::post('/stories/{id}/view', [\App\Http\Controllers\API\StoryController::class, 'view'])->name('stories.view');
    Route::delete('/stories/{id}', [\App\Http\Controllers\API\StoryController::class, 'destroy'])->name('stories.destroy');
    Route::get('/stories/{id}/viewers', [\App\Http\Controllers\API\StoryController::class, 'viewers'])->name('stories.viewers');
    Route::post('/stories/{id}/react', [\App\Http\Controllers\API\StoryController::class, 'react'])->name('stories.react');
    Route::delete('/stories/{id}/react', [\App\Http\Controllers\API\StoryController::class, 'unreact'])->name('stories.unreact');
    Route::post('/stories/{id}/reply', [\App\Http\Controllers\API\StoryController::class, 'reply'])->name('stories.reply');
    Route::post('/stories/{id}/mention-repost', [\App\Http\Controllers\API\StoryController::class, 'mentionRepost'])->name('stories.mentionRepost');
    Route::post('/stories/{id}/capture-event', [\App\Http\Controllers\API\StoryController::class, 'reportCapture'])->name('stories.captureEvent');
    Route::post('/stories/{id}/report', [\App\Http\Controllers\API\StoryController::class, 'report'])->name('stories.report');
    Route::post('/stories/{id}/interact', [\App\Http\Controllers\API\StoryController::class, 'interact'])->name('stories.interact');
    Route::get('/stories/{id}/interactions', [\App\Http\Controllers\API\StoryController::class, 'interactionResults'])->name('stories.interactions');
    Route::post('/stories/{id}/reshare', [\App\Http\Controllers\API\StoryController::class, 'reshare'])->name('stories.reshare');
    Route::post('/stories/{id}/share', [\App\Http\Controllers\API\StoryController::class, 'share'])->name('stories.share');
    Route::post('/story-reports/{id}/accept', [\App\Http\Controllers\API\StoryController::class, 'acceptReport'])->name('stories.acceptReport');
    Route::post('/story-reports/{id}/refuse', [\App\Http\Controllers\API\StoryController::class, 'refuseReport'])->name('stories.refuseReport');

    // Phase 3: highlights
    Route::get('/users/{userId}/highlights', [\App\Http\Controllers\API\HighlightController::class, 'indexForUser'])->name('highlights.indexForUser');
    Route::get('/highlights/{id}', [\App\Http\Controllers\API\HighlightController::class, 'show'])->name('highlights.show');
    Route::post('/highlights', [\App\Http\Controllers\API\HighlightController::class, 'store'])->name('highlights.store');
    Route::match(['patch', 'put'], '/highlights/{id}', [\App\Http\Controllers\API\HighlightController::class, 'update'])->name('highlights.update');
    Route::delete('/highlights/{id}', [\App\Http\Controllers\API\HighlightController::class, 'destroy'])->name('highlights.destroy');
    Route::post('/highlights/{id}/stories', [\App\Http\Controllers\API\HighlightController::class, 'addStory'])->name('highlights.addStory');
    Route::delete('/highlights/{id}/stories/{storyId}', [\App\Http\Controllers\API\HighlightController::class, 'removeStory'])->name('highlights.removeStory');

    // Phase 3: close friends
    Route::get('/close-friends', [\App\Http\Controllers\API\CloseFriendController::class, 'index'])->name('closeFriends.index');
    Route::post('/close-friends/{friendId}', [\App\Http\Controllers\API\CloseFriendController::class, 'store'])->name('closeFriends.store');
    Route::delete('/close-friends/{friendId}', [\App\Http\Controllers\API\CloseFriendController::class, 'destroy'])->name('closeFriends.destroy');

    // Phase 4c: music search (Spotify + iTunes fallback) for the story
    // creator's music sticker.
    Route::get('/music/browse', [\App\Http\Controllers\API\MusicController::class, 'browse'])->name('music.browse');
    Route::get('/music/search', [\App\Http\Controllers\API\MusicController::class, 'search'])->name('music.search');
    Route::get('/music/charts', [\App\Http\Controllers\API\MusicController::class, 'charts'])->name('music.charts');
    Route::get('/music/lyrics', [\App\Http\Controllers\API\MusicController::class, 'lyrics'])->name('music.lyrics');

    // Voice call routes
    Route::get('/call/ably-token', [\App\Http\Controllers\API\CallController::class, 'getAblyToken'])->name('call.ably-token');
    Route::post('/calls/initiate', [\App\Http\Controllers\API\CallController::class, 'initiate'])->name('calls.initiate');
    Route::get('/calls/history', [\App\Http\Controllers\API\CallController::class, 'history'])->name('calls.history');
    Route::get('/calls/{id}', [\App\Http\Controllers\API\CallController::class, 'show'])->name('calls.show');
    Route::post('/calls/{id}/accept', [\App\Http\Controllers\API\CallController::class, 'accept'])->name('calls.accept');
    Route::post('/calls/{id}/reject', [\App\Http\Controllers\API\CallController::class, 'reject'])->name('calls.reject');
    Route::post('/calls/{id}/cancel', [\App\Http\Controllers\API\CallController::class, 'cancel'])->name('calls.cancel');
    Route::post('/calls/{id}/end', [\App\Http\Controllers\API\CallController::class, 'end'])->name('calls.end');
    Route::post('/calls/{id}/token', [\App\Http\Controllers\API\CallController::class, 'token'])->name('calls.token');
});
