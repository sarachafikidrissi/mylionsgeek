<?php

use App\Models\Formation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
    Storage::fake('public');
    $this->withoutVite();

    if (Schema::hasTable('formations') && ! Schema::hasColumn('formations', 'category')) {
        Schema::table('formations', function (Blueprint $table) {
            $table->string('category')->nullable();
            $table->string('promo')->nullable();
            $table->string('user_id')->nullable();
        });
    }
});

function profileSvgUser(array $roles = ['student'], array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => $roles,
        'status' => 'Studying',
        'email_verified_at' => now(),
        'account_state' => 0,
        'image' => 'existing.jpg',
        'cover' => 'img/cover/existing.jpg',
    ], $overrides));
}

function m6Formation(): Formation
{
    $coach = profileSvgUser(['coach'], ['email' => 'm6.coach.'.uniqid('', true).'@example.com']);

    return Formation::query()->create([
        'name' => 'M6 Training',
        'category' => 'coding',
        'start_time' => '2026-09-01',
        'end_time' => '2026-12-01',
        'promo' => 'P1',
        'user_id' => $coach->id,
        'img' => 'default_training.jpg',
    ]);
}

function m6HarmlessSvg(): UploadedFile
{
    return UploadedFile::fake()->create('avatar.svg', 20, 'image/svg+xml');
}

function m6AssertNoSvgStored(): void
{
    $onFakeDisk = collect(Storage::disk('public')->allFiles())
        ->filter(fn (string $path) => str_ends_with(strtolower($path), '.svg'));

    expect($onFakeDisk)->toBeEmpty();

    foreach (['storage/img/profile', 'storage/img/cover'] as $relative) {
        $dir = public_path($relative);
        if (! is_dir($dir)) {
            continue;
        }

        expect(glob($dir.DIRECTORY_SEPARATOR.'*.svg') ?: [])->toBeEmpty();
    }
}

function m6DeletePublicProfileImage(?string $filename): void
{
    if (! is_string($filename) || $filename === '') {
        return;
    }

    $path = public_path('storage/img/profile/'.$filename);
    if (is_file($path)) {
        @unlink($path);
    }
}

test('svg uploads are rejected on the former mimes landmine paths and are not stored', function (string $field, callable $send) {
    $user = profileSvgUser();

    $send($user, m6HarmlessSvg())
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    $user->refresh();

    expect($user->image)->toBe('existing.jpg')
        ->and($user->cover)->toBe('img/cover/existing.jpg');

    m6AssertNoSvgStored();
})->with([
    'change-profile-image' => [
        'image',
        function (User $user, UploadedFile $svg) {
            return test()
                ->actingAs($user)
                ->post('/students/changeProfileImage/'.$user->id, [
                    'image' => $svg,
                ], ['Accept' => 'application/json']);
        },
    ],
    'change-cover' => [
        'cover',
        function (User $user, UploadedFile $svg) {
            return test()
                ->actingAs($user)
                ->post('/students/changeCover/'.$user->id, [
                    'cover' => $svg,
                ], ['Accept' => 'application/json']);
        },
    ],
]);

test('svg avatar is rejected on admin user store and is not persisted', function () {
    $admin = profileSvgUser(['admin'], ['email' => 'm6.store.admin@example.com']);
    $formation = m6Formation();
    $email = 'm6.store.svg@example.com';

    test()
        ->actingAs($admin)
        ->post('/admin/users/store', [
            'name' => 'M6 Svg User',
            'email' => $email,
            'formation_id' => $formation->id,
            'access_studio' => 0,
            'access_cowork' => 0,
            'roles' => ['student'],
            'image' => m6HarmlessSvg(),
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('image');

    expect(User::query()->where('email', $email)->exists())->toBeFalse();
    m6AssertNoSvgStored();
});

test('raster profile and cover uploads still succeed on the former landmine paths', function (string $pathCase) {
    $user = profileSvgUser(['student'], ['email' => 'm6.raster.'.$pathCase.'@example.com']);

    if ($pathCase === 'change-profile-image') {
        test()
            ->actingAs($user)
            ->post('/students/changeProfileImage/'.$user->id, [
                'image' => UploadedFile::fake()->image('avatar.jpg'),
            ])
            ->assertRedirect();

        $user->refresh();
        expect($user->image)->not->toBe('existing.jpg')
            ->and($user->image)->not->toEndWith('.svg')
            ->and(Storage::disk('public')->exists('img/profile/'.$user->image))->toBeTrue();

        return;
    }

    if ($pathCase === 'change-cover') {
        test()
            ->actingAs($user)
            ->post('/students/changeCover/'.$user->id, [
                'cover' => UploadedFile::fake()->image('banner.png'),
            ])
            ->assertRedirect();

        $user->refresh();
        expect($user->cover)->toStartWith('img/cover/')
            ->and($user->cover)->not->toEndWith('.svg')
            ->and(Storage::disk('public')->exists($user->cover))->toBeTrue();

        return;
    }

    $admin = profileSvgUser(['admin'], ['email' => 'm6.raster.store.admin@example.com']);
    $formation = m6Formation();
    $email = 'm6.raster.store.created@example.com';

    test()
        ->actingAs($admin)
        ->from('/admin/users')
        ->post('/admin/users/store', [
            'name' => 'M6 Raster User',
            'email' => $email,
            'formation_id' => $formation->id,
            'access_studio' => 0,
            'access_cowork' => 0,
            'roles' => ['student'],
            'image' => UploadedFile::fake()->image('avatar.jpg'),
        ])
        ->assertRedirect();

    $created = User::query()->where('email', $email)->first();

    expect($created)->not->toBeNull()
        ->and($created->image)->not->toBeEmpty()
        ->and($created->image)->not->toEndWith('.svg')
        ->and(is_file(public_path('storage/img/profile/'.$created->image)))->toBeTrue();

    m6DeletePublicProfileImage($created->image);
})->with([
    'change-profile-image',
    'change-cover',
    'admin-store',
]);

test('svg is still rejected on the other profile and cover upload paths', function (string $field, callable $send) {
    $user = profileSvgUser(['student'], [
        'email' => 'm6.other.'.uniqid('', true).'@example.com',
        'phone' => '0612345678',
        'invite_source' => 'lionsgeek_adult',
    ]);

    $send($user, m6HarmlessSvg())
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    $user->refresh();

    expect($user->image)->toBe('existing.jpg')
        ->and($user->cover)->toBe('img/cover/existing.jpg');

    m6AssertNoSvgStored();
})->with([
    'students-update-image' => [
        'image',
        function (User $user, UploadedFile $svg) {
            return test()
                ->actingAs($user)
                ->put('/students/update/'.$user->id, [
                    'image' => $svg,
                ], ['Accept' => 'application/json']);
        },
    ],
    'students-update-cover' => [
        'cover',
        function (User $user, UploadedFile $svg) {
            return test()
                ->actingAs($user)
                ->put('/students/update/'.$user->id, [
                    'cover' => $svg,
                ], ['Accept' => 'application/json']);
        },
    ],
    'api-profile-image' => [
        'image',
        function (User $user, UploadedFile $svg) {
            Auth::forgetGuards();

            return test()
                ->actingAs($user, 'sanctum')
                ->post('/api/mobile/profile/update', [
                    'image' => $svg,
                ], ['Accept' => 'application/json']);
        },
    ],
    'api-cover' => [
        'cover',
        function (User $user, UploadedFile $svg) {
            Auth::forgetGuards();

            return test()
                ->actingAs($user, 'sanctum')
                ->post('/api/mobile/profile/cover', [
                    'cover' => $svg,
                ], ['Accept' => 'application/json']);
        },
    ],
    'settings-profile' => [
        'image',
        function (User $user, UploadedFile $svg) {
            return test()
                ->actingAs($user)
                ->patch('/settings/profile', [
                    'name' => $user->name,
                    'email' => $user->email,
                    'image' => $svg,
                ], ['Accept' => 'application/json']);
        },
    ],
    'complete-profile' => [
        'image',
        function (User $user, UploadedFile $svg) {
            $token = $user->issueActivationToken();
            $url = URL::temporarySignedRoute(
                'user.complete-profile.update',
                now()->addHours(24),
                ['token' => $token]
            );

            return test()
                ->post($url, [
                    'password' => 'NewPassword1',
                    'password_confirmation' => 'NewPassword1',
                    'image' => $svg,
                ], ['Accept' => 'application/json']);
        },
    ],
]);
