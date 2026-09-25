<?php

use App\Models\Call;
use App\Models\Conversation;
use App\Models\GameSession;
use App\Models\Project;
use App\Models\User;
use App\Services\AblyCapabilityService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function h3User(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => ['student'],
        'status' => 'Studying',
        'email_verified_at' => now(),
    ], $overrides));
}

function h3Caps(): AblyCapabilityService
{
    return app(AblyCapabilityService::class);
}

function h3Keys(array $capabilities): array
{
    return array_keys($capabilities);
}

function h3AssertNoPrivateWildcards(array $capabilities): void
{
    foreach ([
        'chat:conversation:*',
        'project:*',
        'call:user:*',
        'webrtc:*',
        'game:*',
        'presence:*',
    ] as $wildcard) {
        expect($capabilities)->not->toHaveKey($wildcard);
    }
}

test('anonymous cannot request chat or call ably tokens', function () {
    $this->getJson('/api/mobile/chat/ably-token')->assertUnauthorized();
    $this->getJson('/api/mobile/call/ably-token')->assertUnauthorized();
    $this->getJson('/api/games/ably-token')->assertUnauthorized();
    $this->getJson('/api/mobile/notifications/ably-token')->assertUnauthorized();
});

test('chat token capabilities are scoped to conversations the user participates in', function () {
    $userA = h3User(['name' => 'H3 Chat A']);
    $userB = h3User(['name' => 'H3 Chat B']);
    $userC = h3User(['name' => 'H3 Chat C']);

    $shared = Conversation::query()->create([
        'user_one_id' => min($userA->id, $userB->id),
        'user_two_id' => max($userA->id, $userB->id),
    ]);
    $onlyB = Conversation::query()->create([
        'user_one_id' => min($userB->id, $userC->id),
        'user_two_id' => max($userB->id, $userC->id),
    ]);

    $capsA = h3Caps()->chatCapabilities($userA);
    $capsB = h3Caps()->chatCapabilities($userB);

    expect($capsA)->toHaveKey('chat:conversation:'.$shared->id)
        ->and($capsA['chat:conversation:'.$shared->id])->toBe(['subscribe', 'publish'])
        ->and($capsA)->not->toHaveKey('chat:conversation:'.$onlyB->id)
        ->and($capsB)->toHaveKey('chat:conversation:'.$shared->id)
        ->and($capsB)->toHaveKey('chat:conversation:'.$onlyB->id);

    h3AssertNoPrivateWildcards($capsA);
    expect($capsA)->toHaveKey('feed:*')
        ->and($capsA['feed:*'])->toBe(['subscribe'])
        ->and($capsA)->toHaveKey('presence:global')
        ->and($capsA['presence:global'])->toBe(['presence', 'subscribe']);
});

test('chat token ignores client-supplied conversation ids', function () {
    $userA = h3User();
    $userB = h3User();
    $userC = h3User();

    $foreign = Conversation::query()->create([
        'user_one_id' => min($userB->id, $userC->id),
        'user_two_id' => max($userB->id, $userC->id),
    ]);

    $this->actingAs($userA, 'sanctum')
        ->getJson('/api/mobile/chat/ably-token?conversation_id='.$foreign->id.'&conversationId='.$foreign->id);

    $capsA = h3Caps()->chatCapabilities($userA->fresh());
    expect($capsA)->not->toHaveKey('chat:conversation:'.$foreign->id);
    h3AssertNoPrivateWildcards($capsA);
});

test('project token capabilities are scoped to membership and creator', function () {
    $userA = h3User(['name' => 'H3 Project A']);
    $userB = h3User(['name' => 'H3 Project B']);
    $userC = h3User(['name' => 'H3 Project C']);

    $memberProject = Project::query()->create([
        'name' => 'Member Project',
        'status' => 'active',
        'created_by' => $userC->id,
    ]);
    $ownedProject = Project::query()->create([
        'name' => 'Owned Project',
        'status' => 'active',
        'created_by' => $userA->id,
    ]);
    $foreignProject = Project::query()->create([
        'name' => 'Foreign Project',
        'status' => 'active',
        'created_by' => $userB->id,
    ]);

    Schema::dropIfExists('project_users');
    Schema::create('project_users', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('project_id');
        $table->unsignedBigInteger('user_id');
        $table->string('role')->default('member');
        $table->timestamp('invited_at')->nullable();
        $table->timestamp('joined_at')->nullable();
        $table->timestamps();
    });

    $now = now();
    DB::table('project_users')->insert([
        [
            'project_id' => $memberProject->id,
            'user_id' => $userA->id,
            'role' => 'member',
            'joined_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'project_id' => $foreignProject->id,
            'user_id' => $userB->id,
            'role' => 'owner',
            'joined_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    $capsA = h3Caps()->chatCapabilities($userA);
    $capsB = h3Caps()->chatCapabilities($userB);

    expect($capsA)->toHaveKey('project:'.$memberProject->id)
        ->and($capsA['project:'.$memberProject->id])->toBe(['subscribe'])
        ->and($capsA)->toHaveKey('project:'.$ownedProject->id)
        ->and($capsA)->not->toHaveKey('project:'.$foreignProject->id)
        ->and($capsB)->toHaveKey('project:'.$foreignProject->id)
        ->and($capsB)->not->toHaveKey('project:'.$memberProject->id);

    h3AssertNoPrivateWildcards($capsA);
});

test('call inbox is only call:user for the authenticated user', function () {
    $userA = h3User(['name' => 'H3 Call A']);
    $userB = h3User(['name' => 'H3 Call B']);

    $capsA = h3Caps()->callCapabilities($userA);
    $capsB = h3Caps()->callCapabilities($userB);

    expect($capsA)->toHaveKey('call:user:'.$userA->id)
        ->and($capsA['call:user:'.$userA->id])->toBe(['subscribe'])
        ->and($capsA)->not->toHaveKey('call:user:'.$userB->id)
        ->and($capsB)->toHaveKey('call:user:'.$userB->id)
        ->and($capsB)->not->toHaveKey('call:user:'.$userA->id);

    h3AssertNoPrivateWildcards($capsA);
    h3AssertNoPrivateWildcards($capsB);
    expect(h3Keys($capsA))->not->toContain('webrtc:*')
        ->and(h3Keys($capsB))->not->toContain('call:user:*');
});

test('call capabilities do not grant legacy webrtc media channels', function () {
    $caller = h3User(['name' => 'H3 Caller']);
    $callee = h3User(['name' => 'H3 Callee']);
    $stranger = h3User(['name' => 'H3 Stranger']);
    $other = h3User(['name' => 'H3 Other']);

    $live = Call::query()->create([
        'caller_id' => $caller->id,
        'callee_id' => $callee->id,
        'channel_name' => 'call_h3_live',
        'status' => Call::STATUS_PENDING,
    ]);
    Call::query()->create([
        'caller_id' => $caller->id,
        'callee_id' => $other->id,
        'channel_name' => 'call_h3_ended',
        'status' => Call::STATUS_ENDED,
    ]);
    Call::query()->create([
        'caller_id' => $other->id,
        'callee_id' => $stranger->id,
        'channel_name' => 'call_h3_foreign',
        'status' => Call::STATUS_ONGOING,
    ]);

    $capsCaller = h3Caps()->callCapabilities($caller);
    $capsCallee = h3Caps()->callCapabilities($callee);
    $capsStranger = h3Caps()->callCapabilities($stranger);

    expect($capsCaller)->toHaveKey('call:user:'.$caller->id)
        ->and($capsCaller)->not->toHaveKey('webrtc:'.$live->channel_name)
        ->and($capsCallee)->toHaveKey('call:user:'.$callee->id)
        ->and($capsCallee)->not->toHaveKey('webrtc:'.$live->channel_name)
        ->and($capsStranger)->toHaveKey('call:user:'.$stranger->id)
        ->and($capsStranger)->not->toHaveKey('call:user:'.$callee->id)
        ->and(h3Keys($capsCaller))->toBe(['call:user:'.$caller->id]);

    h3AssertNoPrivateWildcards($capsCaller);
    h3AssertNoPrivateWildcards($capsStranger);
});

test('a user cannot receive another users incoming-call payload or agora token through ably capabilities', function () {
    $userA = h3User(['name' => 'H3 Agora A']);
    $userB = h3User(['name' => 'H3 Agora B']);
    $caller = h3User(['name' => 'H3 Agora Caller']);

    Call::query()->create([
        'caller_id' => $caller->id,
        'callee_id' => $userB->id,
        'channel_name' => 'call_h3_b_inbox',
        'status' => Call::STATUS_PENDING,
    ]);

    $capsA = h3Caps()->callCapabilities($userA);
    $capsB = h3Caps()->callCapabilities($userB);

    expect($capsA)->not->toHaveKey('call:user:'.$userB->id)
        ->and($capsA)->not->toHaveKey('webrtc:call_h3_b_inbox')
        ->and($capsB)->toHaveKey('call:user:'.$userB->id)
        ->and($capsB)->not->toHaveKey('webrtc:call_h3_b_inbox')
        ->and(h3Keys($capsB))->toBe(['call:user:'.$userB->id]);

    h3AssertNoPrivateWildcards($capsA);
});

test('call token ignores client-supplied channel names and user ids', function () {
    $userA = h3User();
    $userB = h3User();
    $userC = h3User();

    Call::query()->create([
        'caller_id' => $userB->id,
        'callee_id' => $userC->id,
        'channel_name' => 'call_injected',
        'status' => Call::STATUS_PENDING,
    ]);

    $this->actingAs($userA, 'sanctum')
        ->getJson('/api/mobile/call/ably-token?user_id='.$userB->id.'&channel_name=call_injected&channelName=webrtc:call_injected');

    $capsA = h3Caps()->callCapabilities($userA->fresh());
    expect($capsA)->toHaveKey('call:user:'.$userA->id)
        ->and($capsA)->not->toHaveKey('call:user:'.$userB->id)
        ->and($capsA)->not->toHaveKey('webrtc:call_injected')
        ->and(h3Keys($capsA))->toBe(['call:user:'.$userA->id]);
    h3AssertNoPrivateWildcards($capsA);
});

test('game token capabilities are scoped to joined rooms', function () {
    $userA = h3User(['name' => 'H3 Gamer A']);
    $userB = h3User(['name' => 'H3 Gamer B']);

    $joined = GameSession::query()->create([
        'room_id' => 'ttt-h3-joined',
        'game_type' => 'tictactoe',
        'game_state' => [
            'players' => [
                ['name' => 'H3 Gamer A', 'symbol' => 'X'],
            ],
            'participant_user_ids' => [$userA->id],
        ],
        'last_activity' => now(),
    ]);
    $foreign = GameSession::query()->create([
        'room_id' => 'ttt-h3-foreign',
        'game_type' => 'tictactoe',
        'game_state' => [
            'players' => [
                ['name' => 'H3 Gamer B', 'symbol' => 'X'],
            ],
            'participant_user_ids' => [$userB->id],
        ],
        'last_activity' => now(),
    ]);

    $capsA = h3Caps()->gameCapabilities($userA);
    $capsB = h3Caps()->gameCapabilities($userB);

    expect($capsA)->toHaveKey('game:'.$joined->room_id)
        ->and($capsA['game:'.$joined->room_id])->toBe(['subscribe', 'publish'])
        ->and($capsA)->not->toHaveKey('game:'.$foreign->room_id)
        ->and($capsB)->toHaveKey('game:'.$foreign->room_id)
        ->and($capsB)->not->toHaveKey('game:'.$joined->room_id);

    h3AssertNoPrivateWildcards($capsA);
    h3AssertNoPrivateWildcards($capsB);
});

test('posting game state records the authenticated user as a participant without trusting client ids', function () {
    $userA = h3User(['name' => 'H3 Join A']);
    $userB = h3User(['name' => 'H3 Join B']);

    $this->actingAs($userA)
        ->postJson('/api/games/state/h3-room-join', [
            'game_type' => 'tictactoe',
            'game_state' => [
                'players' => [['name' => 'Alias', 'symbol' => 'X']],
                'participant_user_ids' => [$userB->id],
            ],
        ])
        ->assertOk();

    $capsA = h3Caps()->gameCapabilities($userA);
    $capsB = h3Caps()->gameCapabilities($userB);

    expect($capsA)->toHaveKey('game:h3-room-join')
        ->and($capsB)->not->toHaveKey('game:h3-room-join');
    h3AssertNoPrivateWildcards($capsA);
});

test('same display name does not grant another user ably access to a room', function () {
    $userA = h3User(['name' => 'Shared Gamer Name']);
    $userB = h3User(['name' => 'Shared Gamer Name']);

    GameSession::query()->create([
        'room_id' => 'n10-name-collision',
        'game_type' => 'tictactoe',
        'game_state' => [
            'players' => [
                ['name' => 'Shared Gamer Name', 'symbol' => 'X'],
            ],
            'participant_user_ids' => [$userA->id],
        ],
        'last_activity' => now(),
    ]);

    $capsA = h3Caps()->gameCapabilities($userA);
    $capsB = h3Caps()->gameCapabilities($userB);

    expect($capsA)->toHaveKey('game:n10-name-collision')
        ->and($capsB)->not->toHaveKey('game:n10-name-collision');

    $this->actingAs($userB)
        ->getJson('/api/games/ably-token');

    expect(h3Caps()->gameCapabilities($userB->fresh()))->not->toHaveKey('game:n10-name-collision');
    h3AssertNoPrivateWildcards($capsB);
});

test('renaming to another players display name does not grant ably game capability', function () {
    $userA = h3User(['name' => 'N10 Ably Host']);
    $userB = h3User(['name' => 'N10 Ably Guest']);

    $this->actingAs($userA)
        ->postJson('/api/games/state/n10-ably-rename', [
            'game_type' => 'tictactoe',
            'game_state' => [
                'players' => [['name' => 'N10 Ably Host', 'symbol' => 'X']],
            ],
        ])
        ->assertOk();

    Auth::forgetGuards();
    $this->actingAs($userB, 'sanctum')
        ->postJson('/api/mobile/profile/update', [
            'name' => 'N10 Ably Host',
        ])
        ->assertOk();

    expect($userB->fresh()->name)->toBe('N10 Ably Host')
        ->and(h3Caps()->gameCapabilities($userA))->toHaveKey('game:n10-ably-rename')
        ->and(h3Caps()->gameCapabilities($userB->fresh()))->not->toHaveKey('game:n10-ably-rename');
});

test('notification ably authorization remains scoped to the authenticated user', function () {
    $src = file_get_contents(app_path('Http/Controllers/NotificationController.php'));

    expect($src)->toContain('"notifications:{$user->id}" => [\'subscribe\']')
        ->and($src)->not->toContain('notifications:*')
        ->and($src)->not->toContain('chat:conversation:*')
        ->and($src)->not->toContain('call:user:*');
});

test('token endpoints do not embed private-resource wildcards', function () {
    $files = [
        app_path('Http/Controllers/ChatController.php'),
        app_path('Http/Controllers/API/CallController.php'),
        app_path('Http/Controllers/GamesController.php'),
        app_path('Services/AblyCapabilityService.php'),
    ];

    foreach ($files as $file) {
        $src = file_get_contents($file);
        expect($src)
            ->not->toContain("'chat:conversation:*'")
            ->and($src)->not->toContain('"chat:conversation:*"')
            ->and($src)->not->toContain("'project:*'")
            ->and($src)->not->toContain("'call:user:*'")
            ->and($src)->not->toContain("'webrtc:*'")
            ->and($src)->not->toContain("'game:*'")
            ->and($src)->not->toContain("'presence:*'");
    }
});

test('encode always produces a json object for ably', function () {
    $encodedEmpty = h3Caps()->encode([]);
    $encodedChat = h3Caps()->encode(['feed:*' => ['subscribe']]);

    expect($encodedEmpty)->toBe('{}')
        ->and($encodedChat)->toContain('"feed:*"')
        ->and($encodedChat)->toContain('subscribe');
});
