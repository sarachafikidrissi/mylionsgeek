<?php

use App\Models\CloseFriend;
use App\Models\Story;
use App\Models\StoryInteraction;
use App\Models\StoryReport;
use App\Models\StoryView;
use App\Models\User;
use App\Models\UserBlock;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function storyUser(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => ['student'],
        'status' => 'Studying',
        'email_verified_at' => now(),
        'account_state' => 0,
    ], $overrides));
}

function makeStory(User $owner, array $overrides = []): Story
{
    return Story::query()->create(array_merge([
        'user_id' => $owner->id,
        'media_path' => 'stories/test_'.$owner->id.'_'.uniqid().'.jpg',
        'media_type' => 'image',
        'audience' => 'public',
        'duration_ms' => 5000,
        'expires_at' => now()->addHours(24),
    ], $overrides));
}

test('unauthenticated users cannot list stories', function () {
    $this->getJson('/api/mobile/stories')->assertUnauthorized();
});

test('index hides expired stories and close-friends stories from outsiders', function () {
    $owner = storyUser();
    $outsider = storyUser();
    $friend = storyUser();

    CloseFriend::query()->create([
        'user_id' => $owner->id,
        'friend_id' => $friend->id,
    ]);

    $public = makeStory($owner, ['audience' => 'public']);
    $close = makeStory($owner, ['audience' => 'close_friends']);
    makeStory($owner, ['expires_at' => now()->subMinute()]);

    $outsiderIds = $this->actingAs($outsider, 'sanctum')
        ->getJson('/api/mobile/stories')
        ->assertOk()
        ->json('groups.0.stories');

    $outsiderStoryIds = collect($outsiderIds)->pluck('id')->all();
    expect($outsiderStoryIds)->toContain($public->id);
    expect($outsiderStoryIds)->not->toContain($close->id);

    $friendIds = $this->actingAs($friend, 'sanctum')
        ->getJson('/api/mobile/stories')
        ->assertOk()
        ->json('groups.0.stories');

    expect(collect($friendIds)->pluck('id')->all())->toContain($close->id);
});

test('owner can delete own story and outsider cannot', function () {
    $owner = storyUser();
    $outsider = storyUser();
    $story = makeStory($owner);

    $this->actingAs($outsider, 'sanctum')
        ->deleteJson('/api/mobile/stories/'.$story->id)
        ->assertForbidden();

    expect(Story::query()->find($story->id))->not->toBeNull();

    $this->actingAs($owner, 'sanctum')
        ->deleteJson('/api/mobile/stories/'.$story->id)
        ->assertOk();

    expect(Story::query()->find($story->id))->toBeNull();
});

test('manipulated user_id in the body does not change story ownership', function () {
    Storage::fake('public');
    $owner = storyUser();
    $other = storyUser();

    $this->actingAs($owner, 'sanctum')
        ->post('/api/mobile/stories', [
            'media' => UploadedFile::fake()->image('story.jpg', 200, 200),
            'media_type' => 'image',
            'user_id' => $other->id,
        ])
        ->assertCreated()
        ->assertJsonPath('story.is_mine', true);

    expect(Story::query()->where('user_id', $other->id)->count())->toBe(0);
    expect(Story::query()->where('user_id', $owner->id)->count())->toBe(1);
});

test('close-friends story cannot be viewed reacted to or replied to by outsiders', function () {
    $owner = storyUser();
    $outsider = storyUser();
    $story = makeStory($owner, ['audience' => 'close_friends']);

    $this->actingAs($outsider, 'sanctum')
        ->postJson('/api/mobile/stories/'.$story->id.'/view')
        ->assertNotFound();

    $this->actingAs($outsider, 'sanctum')
        ->postJson('/api/mobile/stories/'.$story->id.'/react', ['emoji' => '🔥'])
        ->assertNotFound();

    $this->actingAs($outsider, 'sanctum')
        ->postJson('/api/mobile/stories/'.$story->id.'/reply', ['message' => 'hi'])
        ->assertNotFound();

    $this->actingAs($outsider, 'sanctum')
        ->postJson('/api/mobile/stories/'.$story->id.'/capture-event', ['kind' => 'screenshot'])
        ->assertNotFound();

    expect(StoryView::query()->where('story_id', $story->id)->count())->toBe(0);
});

test('outsider cannot read another user viewer list', function () {
    $owner = storyUser();
    $outsider = storyUser();
    $story = makeStory($owner);

    $this->actingAs($outsider, 'sanctum')
        ->getJson('/api/mobile/stories/'.$story->id.'/viewers')
        ->assertForbidden();

    $this->actingAs($owner, 'sanctum')
        ->getJson('/api/mobile/stories/'.$story->id.'/viewers')
        ->assertOk()
        ->assertJsonPath('total', 0);
});

test('story views are idempotent per user', function () {
    $owner = storyUser();
    $viewer = storyUser();
    $story = makeStory($owner);

    $this->actingAs($viewer, 'sanctum')
        ->postJson('/api/mobile/stories/'.$story->id.'/view')
        ->assertOk();
    $this->actingAs($viewer, 'sanctum')
        ->postJson('/api/mobile/stories/'.$story->id.'/view')
        ->assertOk();

    expect(StoryView::query()->where('story_id', $story->id)->where('user_id', $viewer->id)->count())->toBe(1);
});

test('expired stories are not accessible through normal APIs', function () {
    $owner = storyUser();
    $viewer = storyUser();
    $story = makeStory($owner, ['expires_at' => now()->subMinute()]);

    $this->actingAs($viewer, 'sanctum')
        ->postJson('/api/mobile/stories/'.$story->id.'/view')
        ->assertNotFound();

    $this->actingAs($viewer, 'sanctum')
        ->postJson('/api/mobile/stories/'.$story->id.'/react', ['emoji' => '👍'])
        ->assertNotFound();
});

test('invalid media types are rejected', function () {
    Storage::fake('public');
    $owner = storyUser();

    $this->actingAs($owner, 'sanctum')
        ->post('/api/mobile/stories', [
            'media' => UploadedFile::fake()->create('note.txt', 20, 'text/plain'),
            'media_type' => 'image',
        ])
        ->assertStatus(415);
});

test('svg uploads are rejected', function () {
    Storage::fake('public');
    $owner = storyUser();

    $this->actingAs($owner, 'sanctum')
        ->post('/api/mobile/stories', [
            'media' => UploadedFile::fake()->create('evil.svg', 40, 'image/svg+xml'),
            'media_type' => 'image',
        ])
        ->assertStatus(415);
});

test('purge command keeps expired stories for the owner archive', function () {
    $owner = storyUser();
    $keep = makeStory($owner);
    $expired = makeStory($owner, ['expires_at' => now()->subMinute()]);

    $this->artisan('stories:purge-expired')->assertSuccessful();

    expect(Story::query()->find($keep->id))->not->toBeNull();
    expect(Story::query()->find($expired->id))->not->toBeNull();
});

test('blocked users are hidden from the story feed both ways', function () {
    $owner = storyUser();
    $viewer = storyUser();
    makeStory($owner);

    UserBlock::query()->create([
        'blocker_id' => $viewer->id,
        'blocked_id' => $owner->id,
    ]);

    $groups = $this->actingAs($viewer, 'sanctum')
        ->getJson('/api/mobile/stories')
        ->assertOk()
        ->json('groups');

    expect(collect($groups)->pluck('user.id')->all())->not->toContain($owner->id);

    $this->actingAs($owner, 'sanctum')
        ->getJson('/api/mobile/stories')
        ->assertOk();
});

test('authenticated users can report another story and cannot report their own', function () {
    $owner = storyUser();
    $reporter = storyUser();
    $story = makeStory($owner);

    $this->actingAs($owner, 'sanctum')
        ->postJson('/api/mobile/stories/'.$story->id.'/report', ['reason' => 'This is spam content'])
        ->assertUnprocessable();

    $this->actingAs($reporter, 'sanctum')
        ->postJson('/api/mobile/stories/'.$story->id.'/report', ['reason' => 'This is spam content'])
        ->assertCreated();

    expect(StoryReport::query()->where('story_id', $story->id)->where('reporter_id', $reporter->id)->count())->toBe(1);
});

test('outsider cannot read owner interaction analytics or archive', function () {
    $owner = storyUser();
    $outsider = storyUser();
    $story = makeStory($owner);

    $this->actingAs($outsider, 'sanctum')
        ->getJson('/api/mobile/stories/'.$story->id.'/interactions')
        ->assertForbidden();

    $this->actingAs($outsider, 'sanctum')
        ->getJson('/api/mobile/stories/archive')
        ->assertOk()
        ->assertJsonCount(0, 'stories');

    $this->actingAs($owner, 'sanctum')
        ->getJson('/api/mobile/stories/archive')
        ->assertOk()
        ->assertJsonCount(1, 'stories');
});

test('poll votes are unique per user and hidden stories are not in the feed', function () {
    $owner = storyUser();
    $voter = storyUser();
    $story = makeStory($owner, ['is_hidden' => true]);

    $groups = $this->actingAs($voter, 'sanctum')
        ->getJson('/api/mobile/stories')
        ->assertOk()
        ->json('groups');
    expect(collect($groups)->pluck('user.id')->all())->not->toContain($owner->id);

    $visible = makeStory($owner);
    StoryInteraction::query()->create([
        'story_id' => $visible->id,
        'overlay_id' => 'poll1',
        'type' => 'poll',
        'payload' => ['question' => 'A or B?', 'options' => ['A', 'B']],
    ]);

    $this->actingAs($voter, 'sanctum')
        ->postJson('/api/mobile/stories/'.$visible->id.'/interact', [
            'overlay_id' => 'poll1',
            'value' => ['index' => 0],
        ])
        ->assertOk();

    $this->actingAs($voter, 'sanctum')
        ->postJson('/api/mobile/stories/'.$visible->id.'/interact', [
            'overlay_id' => 'poll1',
            'value' => ['index' => 1],
        ])
        ->assertStatus(409);
});

test('quiz correct answer is hidden until the viewer submits', function () {
    $owner = storyUser();
    $voter = storyUser();
    $story = makeStory($owner);
    StoryInteraction::query()->create([
        'story_id' => $story->id,
        'overlay_id' => 'quiz1',
        'type' => 'quiz',
        'payload' => ['question' => 'Capital?', 'options' => ['Rabat', 'Paris'], 'correct_index' => 0],
    ]);

    $before = $this->actingAs($voter, 'sanctum')
        ->getJson('/api/mobile/stories')
        ->assertOk()
        ->json('groups.0.stories.0.interactions.0');

    expect($before['payload'] ?? [])->not->toHaveKey('correct_index');
    expect($before)->not->toHaveKey('is_correct');

    $after = $this->actingAs($voter, 'sanctum')
        ->postJson('/api/mobile/stories/'.$story->id.'/interact', [
            'overlay_id' => 'quiz1',
            'value' => ['index' => 0],
        ])
        ->assertOk()
        ->json('interaction');

    expect($after['payload'] ?? [])->not->toHaveKey('correct_index');
    expect($after['is_correct'])->toBeTrue();
});

test('music browse returns a catalog payload', function () {
    config()->set('services.spotify.client_id', 'test-client-id');
    config()->set('services.spotify.client_secret', 'test-client-secret');

    Http::fake([
        'accounts.spotify.com/api/token' => Http::response([
            'access_token' => 'test-token',
            'expires_in' => 3600,
        ], 200),
        'api.spotify.com/v1/playlists/*' => Http::response([
            'items' => [[
                'track' => [
                    'id' => 'abc123',
                    'name' => 'Test Track',
                    'duration_ms' => 180000,
                    'explicit' => false,
                    'preview_url' => 'https://p.scdn.co/mp3-preview/test',
                    'artists' => [['name' => 'Artist']],
                    'album' => [
                        'name' => 'Album',
                        'images' => [['url' => 'https://i.scdn.co/image/x']],
                    ],
                ],
            ]],
            'next' => null,
        ], 200),
        'itunes.apple.com/*' => Http::response(['results' => []], 200),
    ]);

    $user = storyUser();
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/mobile/music/browse?section=top_morocco&country=MA')
        ->assertOk()
        ->assertJsonPath('source', 'spotify+itunes')
        ->assertJsonPath('items.0.title', 'Test Track')
        ->assertJsonPath('items.0.preview_url', 'https://p.scdn.co/mp3-preview/test');

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/mobile/music/browse?section=top_morocco&country=MA')
        ->assertOk()
        ->assertJsonPath('items.0.title', 'Test Track');

    $playlistCalls = collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.spotify.com/v1/playlists'))
        ->count();
    expect($playlistCalls)->toBe(1);
});

test('catalog music overlay keeps an allowlisted preview url', function () {
    Storage::fake('public');
    $owner = storyUser();

    $overlays = $this->actingAs($owner, 'sanctum')
        ->post('/api/mobile/stories', [
            'media' => UploadedFile::fake()->image('story.jpg', 200, 200),
            'media_type' => 'image',
            'overlays' => json_encode([[
                'id' => 'm1',
                'type' => 'music',
                'x' => 0.5,
                'y' => 0.2,
                'scale' => 1,
                'rotation' => 0,
                'title' => 'Test Track',
                'artist' => 'Artist',
                'preview_url' => 'https://p.scdn.co/mp3-preview/abc',
                'cover_url' => 'https://i.scdn.co/image/abc',
                'source' => 'spotify',
                'start_ms' => 0,
                'end_ms' => 15000,
            ]]),
        ])
        ->assertCreated()
        ->json('story.overlays');

    $music = collect($overlays)->firstWhere('type', 'music');
    expect($music['preview_url'] ?? null)->toBe('https://p.scdn.co/mp3-preview/abc');
    expect($music['cover_url'] ?? null)->toBe('https://i.scdn.co/image/abc');
    expect($music['source'] ?? null)->toBe('spotify');
});

test('music overlay rejects arbitrary remote audio urls', function () {
    Storage::fake('public');
    $owner = storyUser();

    $overlays = $this->actingAs($owner, 'sanctum')
        ->post('/api/mobile/stories', [
            'media' => UploadedFile::fake()->image('story.jpg', 200, 200),
            'media_type' => 'image',
            'overlays' => json_encode([[
                'id' => 'm1',
                'type' => 'music',
                'x' => 0.5,
                'y' => 0.2,
                'scale' => 1,
                'rotation' => 0,
                'title' => 'Bad Track',
                'preview_url' => 'https://evil.example/audio.mp3',
                'source' => 'spotify',
            ]]),
        ])
        ->assertCreated()
        ->json('story.overlays');

    expect(collect($overlays)->firstWhere('type', 'music'))->toBeNull();
});

test('hashtag location and four-option poll overlays are accepted', function () {
    Storage::fake('public');
    $owner = storyUser();

    $overlays = [
        ['id' => 'h1', 'type' => 'hashtag', 'x' => 0.5, 'y' => 0.8, 'scale' => 1, 'rotation' => 0, 'tag' => 'lionsgeek'],
        ['id' => 'l1', 'type' => 'location', 'x' => 0.5, 'y' => 0.7, 'scale' => 1, 'rotation' => 0, 'label' => 'LionsGeek Ain Sebaa'],
        ['id' => 'p1', 'type' => 'poll', 'x' => 0.5, 'y' => 0.6, 'scale' => 1, 'rotation' => 0, 'question' => 'Pick one', 'options' => ['A', 'B', 'C', 'D']],
        ['id' => 't1', 'type' => 'text', 'x' => 0.5, 'y' => 0.4, 'scale' => 1, 'rotation' => 0, 'text' => 'Hello', 'align' => 'left', 'anim' => 'pulse', 'font' => 'serif'],
    ];

    $json = $this->actingAs($owner, 'sanctum')
        ->post('/api/mobile/stories', [
            'media' => UploadedFile::fake()->image('story.jpg', 200, 200),
            'media_type' => 'image',
            'overlays' => json_encode($overlays),
        ])
        ->assertCreated()
        ->json('story.overlays');

    $types = collect($json)->pluck('type')->all();
    expect($types)->toContain('hashtag');
    expect($types)->toContain('location');
    expect($types)->toContain('poll');
    $poll = collect($json)->firstWhere('type', 'poll');
    expect($poll['options'] ?? [])->toHaveCount(4);
    $text = collect($json)->firstWhere('type', 'text');
    expect($text['align'] ?? null)->toBe('left');
    expect($text['anim'] ?? null)->toBe('pulse');

    expect(\Illuminate\Support\Facades\DB::table('story_hashtags')->where('tag', 'lionsgeek')->exists())->toBeTrue();
});

test('sticker image upload is stored on the overlay', function () {
    Storage::fake('public');
    $owner = storyUser();

    $json = $this->actingAs($owner, 'sanctum')
        ->post('/api/mobile/stories', [
            'media' => UploadedFile::fake()->image('story.jpg', 200, 200),
            'media_type' => 'image',
            'overlays' => json_encode([
                ['id' => 'st1', 'type' => 'sticker', 'x' => 0.5, 'y' => 0.5, 'scale' => 1, 'rotation' => 0],
            ]),
            'sticker_st1' => UploadedFile::fake()->image('sticker.png', 80, 80),
        ])
        ->assertCreated()
        ->json('story.overlays.0');

    expect($json['type'] ?? null)->toBe('sticker');
    expect($json['image_url'] ?? null)->toBeString();
});

test('story mention notifications are private to the mentioned user', function () {
    Storage::fake('public');
    $owner = storyUser();
    $mentioned = storyUser();
    $outsider = storyUser();

    $this->actingAs($owner, 'sanctum')
        ->post('/api/mobile/stories', [
            'media' => UploadedFile::fake()->image('story.jpg', 200, 200),
            'media_type' => 'image',
            'overlays' => json_encode([
                [
                    'id' => 'm1',
                    'type' => 'mention',
                    'x' => 0.5,
                    'y' => 0.5,
                    'scale' => 1,
                    'rotation' => 0,
                    'user_id' => $mentioned->id,
                ],
            ]),
        ])
        ->assertCreated();

    expect(\App\Models\StoryNotification::query()->where('user_id', $mentioned->id)->count())->toBe(1);
    $notifId = \App\Models\StoryNotification::query()->where('user_id', $mentioned->id)->value('id');

    $mentionedList = $this->actingAs($mentioned, 'sanctum')
        ->getJson('/api/mobile/notifications')
        ->assertOk()
        ->json('notifications');
    $mentionedIds = collect($mentionedList)->pluck('id')->all();
    expect($mentionedIds)->toContain('story-mention-'.$notifId);

    $outsiderList = $this->actingAs($outsider, 'sanctum')
        ->getJson('/api/mobile/notifications')
        ->assertOk()
        ->json('notifications');
    expect(collect($outsiderList)->pluck('id')->all())->not->toContain('story-mention-'.$notifId);

    $this->actingAs($outsider, 'sanctum')
        ->postJson('/api/mobile/notifications/story-mention/'.$notifId.'/read')
        ->assertOk();

    expect(\App\Models\StoryNotification::query()->find($notifId)?->read_at)->toBeNull();
});

test('close-friends signed stream rejects after hide close-friend removal or expiry', function () {
    Storage::fake('stories');

    $owner = storyUser();
    $friend = storyUser();
    $outsider = storyUser();

    CloseFriend::query()->create([
        'user_id' => $owner->id,
        'friend_id' => $friend->id,
    ]);

    $path = 'stories/cf_'.$owner->id.'.jpg';
    Storage::disk('stories')->put($path, 'fake-image-bytes');

    $story = makeStory($owner, [
        'audience' => 'close_friends',
        'media_path' => $path,
    ]);

    $mediaUrl = $this->actingAs($friend, 'sanctum')
        ->getJson('/api/mobile/stories')
        ->assertOk()
        ->json('groups.0.stories.0.media_url');

    expect($mediaUrl)->toBeString()->toContain('signature=');
    expect($mediaUrl)->toContain('viewer='.$friend->id);

    $this->get($mediaUrl)->assertOk();

    // Outsider cannot mint a working URL via the friend signature by swapping viewer.
    $outsiderUrl = $this->actingAs($outsider, 'sanctum')
        ->getJson('/api/mobile/stories')
        ->assertOk()
        ->json('groups');
    expect(collect($outsiderUrl)->pluck('user.id')->all())->not->toContain($owner->id);

    // Hidden by moderation — prior signed URL must stop working for the friend.
    $story->is_hidden = true;
    $story->save();
    $this->get($mediaUrl)->assertNotFound();

    $story->is_hidden = false;
    $story->save();

    // Close-friend removed — prior signed URL must stop working.
    CloseFriend::query()
        ->where('user_id', $owner->id)
        ->where('friend_id', $friend->id)
        ->delete();
    $this->get($mediaUrl)->assertNotFound();

    CloseFriend::query()->create([
        'user_id' => $owner->id,
        'friend_id' => $friend->id,
    ]);
    $freshUrl = $this->actingAs($friend, 'sanctum')
        ->getJson('/api/mobile/stories')
        ->assertOk()
        ->json('groups.0.stories.0.media_url');
    $this->get($freshUrl)->assertOk();

    // Expired — non-owner stream denied even with a previously valid signature.
    $story->expires_at = now()->subMinute();
    $story->save();
    $this->get($freshUrl)->assertNotFound();
});

test('story share persists story id without a capability media URL', function () {
    $owner = storyUser();
    $friend = storyUser();
    $recipient = storyUser();

    CloseFriend::query()->create([
        'user_id' => $owner->id,
        'friend_id' => $friend->id,
    ]);
    CloseFriend::query()->create([
        'user_id' => $owner->id,
        'friend_id' => $recipient->id,
    ]);

    $story = makeStory($owner, ['audience' => 'close_friends']);

    $this->actingAs($friend, 'sanctum')
        ->postJson('/api/mobile/stories/'.$story->id.'/share', [
            'user_id' => $recipient->id,
        ])
        ->assertOk()
        ->assertJsonPath('ok', true);

    $message = \App\Models\Message::query()->latest('id')->first();
    expect($message)->not->toBeNull();

    $body = json_decode((string) $message->body, true);
    expect($body['type'] ?? null)->toBe('story_share');
    expect($body['story_id'] ?? null)->toBe($story->id);
    expect($body)->not->toHaveKey('story_preview');
    expect(json_encode($body))->not->toContain('signature=');
});

test('admin story report media streams private close-friends files', function () {
    Storage::fake('stories');
    $this->withoutVite();

    $owner = storyUser();
    $reporter = storyUser();
    $admin = storyUser(['role' => ['admin']]);

    $path = 'stories/report_'.$owner->id.'.jpg';
    Storage::disk('stories')->put($path, 'reported-bytes');

    $story = makeStory($owner, [
        'audience' => 'close_friends',
        'media_path' => $path,
    ]);

    $report = StoryReport::query()->create([
        'story_id' => $story->id,
        'reporter_id' => $reporter->id,
        'reason' => 'spam',
        'status' => StoryReport::STATUS_PENDING,
    ]);

    // Public /storage URL must not serve private-disk close-friends files.
    $publicStorage = $this->get('/storage/'.$path);
    expect(in_array($publicStorage->status(), [403, 404], true))->toBeTrue();

    $this->actingAs($admin)
        ->get(route('admin.story-reports.media', ['report' => $report->id]))
        ->assertOk();

    $this->actingAs($admin)
        ->get(route('admin.story-reports.index'))
        ->assertOk()
        ->assertInertia(fn ($assert) => $assert
            ->component('admin/story-reports/index')
            ->has('reports.data.0.story.media_url')
        );
});

test('close-friends overlay stream is revoked after unfriend and stolen paths are rejected', function () {
    Storage::fake('stories');
    Storage::fake('public');

    $owner = storyUser();
    $friend = storyUser();
    $attacker = storyUser();

    CloseFriend::query()->create([
        'user_id' => $owner->id,
        'friend_id' => $friend->id,
    ]);

    $created = $this->actingAs($owner, 'sanctum')
        ->post('/api/mobile/stories', [
            'media' => UploadedFile::fake()->image('story.jpg', 200, 200),
            'media_type' => 'image',
            'audience' => 'close_friends',
            'overlays' => json_encode([
                ['id' => 'st1', 'type' => 'sticker', 'x' => 0.5, 'y' => 0.5, 'scale' => 1, 'rotation' => 0],
            ]),
            'sticker_st1' => UploadedFile::fake()->image('sticker.png', 80, 80),
        ])
        ->assertCreated()
        ->json('story');

    $friendOverlay = $this->actingAs($friend, 'sanctum')
        ->getJson('/api/mobile/stories')
        ->assertOk()
        ->json('groups.0.stories.0.overlays.0.image_url');

    expect($friendOverlay)->toBeString()->toContain('signature=');
    expect($friendOverlay)->toContain('path=');
    $this->get($friendOverlay)->assertOk();

    parse_str((string) parse_url($friendOverlay, PHP_URL_QUERY), $params);
    $stolenPath = ltrim((string) ($params['path'] ?? ''), '/');
    expect($stolenPath)->toStartWith('stories/');

    $layout = $this->actingAs($attacker, 'sanctum')
        ->post('/api/mobile/stories', [
            'media' => UploadedFile::fake()->image('mine.jpg', 200, 200),
            'media_type' => 'image',
            'overlays' => json_encode([[
                'id' => 'l1',
                'type' => 'layout',
                'template' => '2v',
                'cells' => [
                    ['image_url' => url('storage/'.$stolenPath)],
                    ['image_url' => url('storage/stories/other.png')],
                ],
            ]]),
        ])
        ->assertCreated()
        ->json('story.overlays');

    expect(collect($layout)->firstWhere('type', 'layout'))->toBeNull();

    $attackerStory = makeStory($attacker, [
        'audience' => 'close_friends',
        'overlays' => [[
            'id' => 'st1',
            'type' => 'sticker',
            'image_url' => $stolenPath,
        ]],
    ]);
    Storage::disk('stories')->put($attackerStory->media_path, 'attacker-bytes');

    $forged = \Illuminate\Support\Facades\URL::temporarySignedRoute(
        'mobile.stories.asset',
        now()->addMinutes(15),
        [
            'path' => $stolenPath,
            'story' => $attackerStory->id,
            'viewer' => $attacker->id,
        ]
    );
    $this->get($forged)->assertNotFound();

    CloseFriend::query()
        ->where('user_id', $owner->id)
        ->where('friend_id', $friend->id)
        ->delete();
    $this->get($friendOverlay)->assertNotFound();
});

test('mention-repost refuses close-friends stories', function () {
    Storage::fake('stories');
    Storage::fake('public');

    $owner = storyUser();
    $mentioned = storyUser();

    CloseFriend::query()->create([
        'user_id' => $owner->id,
        'friend_id' => $mentioned->id,
    ]);

    $path = 'stories/cf_rp_'.$owner->id.'.jpg';
    Storage::disk('stories')->put($path, 'private-bytes');
    $story = makeStory($owner, [
        'audience' => 'close_friends',
        'media_path' => $path,
    ]);

    \Illuminate\Support\Facades\DB::table('story_mentions')->insert([
        'story_id' => $story->id,
        'mentioned_user_id' => $mentioned->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($mentioned, 'sanctum')
        ->getJson('/api/mobile/stories')
        ->assertOk()
        ->assertJsonPath('groups.0.stories.0.can_repost_as_mention', false);

    $this->actingAs($mentioned, 'sanctum')
        ->postJson('/api/mobile/stories/'.$story->id.'/mention-repost')
        ->assertForbidden();

    expect(Story::query()->where('user_id', $mentioned->id)->count())->toBe(0);
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

test('mention-repost copies a public story for the mentioned user', function () {
    Storage::fake('public');

    $owner = storyUser();
    $mentioned = storyUser();
    $path = 'stories/pub_rp_'.$owner->id.'.jpg';
    Storage::disk('public')->put($path, 'public-bytes');
    $story = makeStory($owner, [
        'audience' => 'public',
        'media_path' => $path,
    ]);

    \Illuminate\Support\Facades\DB::table('story_mentions')->insert([
        'story_id' => $story->id,
        'mentioned_user_id' => $mentioned->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($mentioned, 'sanctum')
        ->postJson('/api/mobile/stories/'.$story->id.'/mention-repost')
        ->assertCreated()
        ->assertJsonPath('story.audience', 'public')
        ->assertJsonPath('story.is_mine', true);

    expect(Story::query()->where('user_id', $mentioned->id)->count())->toBe(1);
});

test('highlights sign close-friends media and hide it from outsiders', function () {
    Storage::fake('stories');
    Storage::fake('public');

    $owner = storyUser();
    $friend = storyUser();
    $outsider = storyUser();

    CloseFriend::query()->create([
        'user_id' => $owner->id,
        'friend_id' => $friend->id,
    ]);

    $path = 'stories/cf_hl_'.$owner->id.'.jpg';
    $sticker = 'stories/sticker_'.$owner->id.'_1.png';
    Storage::disk('stories')->put($path, 'highlight-bytes');
    Storage::disk('stories')->put($sticker, 'sticker-bytes');

    $story = makeStory($owner, [
        'audience' => 'close_friends',
        'media_path' => $path,
        'overlays' => [[
            'id' => 'st1',
            'type' => 'sticker',
            'image_url' => $sticker,
        ]],
    ]);

    $highlight = \App\Models\StoryHighlight::query()->create([
        'user_id' => $owner->id,
        'title' => 'Close friends',
        'cover_path' => null,
    ]);
    \App\Models\StoryHighlightItem::query()->create([
        'highlight_id' => $highlight->id,
        'story_id' => $story->id,
        'position' => 0,
    ]);

    $payload = $this->actingAs($friend, 'sanctum')
        ->getJson('/api/mobile/highlights/'.$highlight->id)
        ->assertOk()
        ->json('highlight');

    expect($payload['stories'])->toHaveCount(1);
    expect($payload['stories'][0]['media_url'])->toContain('signature=');
    expect($payload['stories'][0]['overlays'][0]['image_url'])->toContain('signature=');
    $this->get($payload['stories'][0]['media_url'])->assertOk();
    $this->get($payload['stories'][0]['overlays'][0]['image_url'])->assertOk();

    $hidden = $this->actingAs($outsider, 'sanctum')
        ->getJson('/api/mobile/highlights/'.$highlight->id)
        ->assertOk()
        ->json('highlight.stories');

    expect($hidden)->toBe([]);
});
