<?php

use App\Models\Attendance;
use App\Models\AttendanceListe;
use App\Models\FaceEnrollment;
use App\Models\Formation;
use App\Models\User;
use App\Services\FaceVerification\FaceVerificationResult;
use App\Services\FaceVerification\FaceVerificationService;
use App\Services\FaceVerification\RekognitionFaceVerificationService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeRekognitionClient;
use Tests\TestCase;

beforeEach(function () {
    Schema::dropAllTables();

    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email');
        $table->timestamp('email_verified_at')->nullable();
        $table->string('password');
        $table->string('remember_token')->nullable();
        $table->json('role')->nullable();
        $table->string('status')->default('Studying');
        $table->integer('formation_id')->nullable();
        $table->timestamps();
    });

    Schema::create('formations', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('img')->default('default_training.jpg');
        $table->string('category')->nullable();
        $table->string('start_time')->nullable();
        $table->string('end_time')->nullable();
        $table->integer('user_id')->nullable();
        $table->string('promo')->nullable();
        $table->boolean('is_active')->default(false);
        $table->timestamps();
    });

    Schema::create('attendances', function (Blueprint $table) {
        $table->id();
        $table->integer('formation_id');
        $table->string('attendance_day');
        $table->string('staff_name');
        $table->timestamps();
    });

    Schema::create('attendance_lists', function (Blueprint $table) {
        $table->id();
        $table->integer('user_id');
        $table->integer('attendance_id');
        $table->string('attendance_day');
        $table->string('morning')->nullable();
        $table->string('lunch')->nullable();
        $table->string('evening')->nullable();
        $table->timestamps();
    });

    Schema::create('discipline_notifications', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->string('message_notification')->nullable();
        $table->decimal('discipline_change', 5, 2)->nullable();
        $table->string('path')->nullable();
        $table->string('type')->nullable();
        $table->timestamps();
    });

    Schema::create('notes', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('attendance_id')->nullable();
        $table->string('note');
        $table->string('author')->nullable();
        $table->timestamps();
    });

    Schema::create('face_enrollments', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id')->unique();
        $table->string('disk');
        $table->string('path');
        $table->unsignedBigInteger('enrolled_by')->nullable();
        $table->timestamp('enrolled_at');
        $table->timestamps();
    });

    $this->formation = Formation::create([
        'name' => 'Test Formation',
        'category' => 'coding',
        'start_time' => '2025-01-01',
        'user_id' => null,
    ]);

    config(['attendance.allowed_ips' => ['203.0.113.1']]);

    $this->withoutMiddleware([
        \App\Http\Middleware\HandleInertiaRequests::class,
        \App\Http\Middleware\EnsureOrganisationOnboarded::class,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

function m8FaceStudent(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => ['student'],
        'status' => 'Studying',
        'formation_id' => test()->formation->id,
        'email_verified_at' => now(),
    ], $overrides));
}

function m8FaceStaff(): User
{
    return User::factory()->create([
        'role' => ['coach'],
        'email' => 'm8.face.coach.'.uniqid('', true).'@example.com',
        'email_verified_at' => now(),
    ]);
}

function seedPrivateFaceEnrollment(User $student, User $staff): FaceEnrollment
{
    $path = $student->id.'/reference.jpg';
    Storage::disk('face_enrollments')->put($path, 'private-reference-bytes');

    return FaceEnrollment::query()->create([
        'user_id' => $student->id,
        'disk' => 'face_enrollments',
        'path' => $path,
        'enrolled_by' => $staff->id,
        'enrolled_at' => now(),
    ]);
}

function freezeM8FaceTime(string $time): void
{
    Carbon::setTestNow(Carbon::parse(Carbon::now()->toDateString().' '.$time, 'Africa/Casablanca'));
}

function postM8FaceCheckIn(TestCase $test, User $actor, string $path, array $extra = []): Illuminate\Testing\TestResponse
{
    $payload = array_merge([
        'formation_id' => $test->formation->id,
        'attendance_day' => Carbon::now()->toDateString(),
        'live_photo' => m8LivePhoto(),
    ], $extra);

    return $test->actingAs($actor, $path === '/api/mobile/attendance/check-in' ? 'sanctum' : 'web')
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])
        ->post($path, $payload, [
            'Accept' => 'application/json',
        ]);
}

test('matching face is verified and creates attendance', function () {
    freezeM8FaceTime('09:42:00');
    $client = bindRekognitionFaceVerifier();
    $client->similarity = 97.5;
    $student = m8FaceStudent();
    seedPrivateFaceEnrollment($student, m8FaceStaff());

    postM8FaceCheckIn($this, $student, '/api/mobile/attendance/check-in')
        ->assertOk()
        ->assertJsonPath('slot', 'morning')
        ->assertJsonPath('status', 'present');

    expect(AttendanceListe::query()->where('user_id', $student->id)->exists())->toBeTrue();
});

test('non-matching face is rejected and creates no attendance', function () {
    freezeM8FaceTime('09:42:00');
    $client = bindRekognitionFaceVerifier();
    $client->similarity = 12.0;
    $student = m8FaceStudent();
    seedPrivateFaceEnrollment($student, m8FaceStaff());
    $listCountBefore = AttendanceListe::count();

    postM8FaceCheckIn($this, $student, '/api/mobile/attendance/check-in')
        ->assertUnprocessable()
        ->assertJson(['message' => 'Face not recognized.']);

    expect(AttendanceListe::count())->toBe($listCountBefore)
        ->and(Attendance::count())->toBe(0);
});

test('live photo with no face is rejected', function () {
    freezeM8FaceTime('09:42:00');
    $client = bindRekognitionFaceVerifier();
    $client->detectFaceCount = 0;
    $student = m8FaceStudent();
    seedPrivateFaceEnrollment($student, m8FaceStaff());

    postM8FaceCheckIn($this, $student, '/api/mobile/attendance/check-in')
        ->assertUnprocessable()
        ->assertJson(['message' => 'Face not recognized.']);

    expect($client->compareCalls)->toBe(0)
        ->and(AttendanceListe::count())->toBe(0);
});

test('live photo with multiple faces is rejected', function () {
    freezeM8FaceTime('09:42:00');
    $client = bindRekognitionFaceVerifier();
    $client->detectFaceCount = 2;
    $student = m8FaceStudent();
    seedPrivateFaceEnrollment($student, m8FaceStaff());

    postM8FaceCheckIn($this, $student, '/api/mobile/attendance/check-in')
        ->assertUnprocessable()
        ->assertJson(['message' => 'Face not recognized.']);

    expect($client->compareCalls)->toBe(0)
        ->and(AttendanceListe::count())->toBe(0);
});

test('missing enrollment does not create attendance', function () {
    freezeM8FaceTime('09:42:00');
    bindRekognitionFaceVerifier();
    $student = m8FaceStudent();

    postM8FaceCheckIn($this, $student, '/api/mobile/attendance/check-in')
        ->assertStatus(503)
        ->assertJson(['message' => 'Unable to verify your identity.']);

    expect(AttendanceListe::count())->toBe(0)
        ->and(Attendance::count())->toBe(0);
});

test('aws unavailable returns unavailable and creates no attendance', function () {
    freezeM8FaceTime('09:42:00');
    $client = bindRekognitionFaceVerifier();
    $client->throwOnCompare = true;
    $student = m8FaceStudent();
    seedPrivateFaceEnrollment($student, m8FaceStaff());

    postM8FaceCheckIn($this, $student, '/api/mobile/attendance/check-in')
        ->assertStatus(503)
        ->assertExactJson(['message' => 'Unable to verify your identity.']);

    expect(AttendanceListe::count())->toBe(0);
});

test('invalid credentials keep verification unavailable', function () {
    freezeM8FaceTime('09:42:00');
    $student = m8FaceStudent();

    config([
        'face_verification.api_key' => '',
        'face_verification.api_secret' => '',
    ]);
    app()->forgetInstance(FaceVerificationService::class);

    postM8FaceCheckIn($this, $student, '/api/mobile/attendance/check-in')
        ->assertStatus(503)
        ->assertJson(['message' => 'Unable to verify your identity.']);

    expect(AttendanceListe::count())->toBe(0);
});

test('similarity below threshold is rejected', function () {
    freezeM8FaceTime('09:42:00');
    $client = bindRekognitionFaceVerifier();
    $client->similarity = 89.9;
    $student = m8FaceStudent();
    seedPrivateFaceEnrollment($student, m8FaceStaff());

    postM8FaceCheckIn($this, $student, '/api/mobile/attendance/check-in')
        ->assertUnprocessable()
        ->assertJson(['message' => 'Face not recognized.']);

    expect($client->compareThresholds)->toBe([90.0])
        ->and(AttendanceListe::count())->toBe(0);
});

test('client cannot influence the similarity threshold', function () {
    freezeM8FaceTime('09:42:00');
    $client = bindRekognitionFaceVerifier();
    $client->similarity = 50.0;
    $student = m8FaceStudent();
    seedPrivateFaceEnrollment($student, m8FaceStaff());

    postM8FaceCheckIn($this, $student, '/api/mobile/attendance/check-in', [
        'similarity' => 10,
        'similarity_threshold' => 10,
        'FACE_VERIFICATION_MIN_SIMILARITY' => 10,
        'min_similarity' => 10,
    ])
        ->assertUnprocessable()
        ->assertJson(['message' => 'Face not recognized.']);

    expect($client->compareThresholds)->toBe([90.0])
        ->and(AttendanceListe::count())->toBe(0);
});

test('client verified true is ignored', function () {
    freezeM8FaceTime('09:42:00');
    $client = bindRekognitionFaceVerifier();
    $client->similarity = 10.0;
    $student = m8FaceStudent();
    seedPrivateFaceEnrollment($student, m8FaceStaff());

    postM8FaceCheckIn($this, $student, '/api/mobile/attendance/check-in', [
        'verified' => true,
        'face_verified' => true,
    ])
        ->assertUnprocessable()
        ->assertJson(['message' => 'Face not recognized.']);

    expect(AttendanceListe::count())->toBe(0);
});

test('client-provided similarity and embedding are ignored', function () {
    freezeM8FaceTime('09:42:00');
    $client = bindRekognitionFaceVerifier();
    $client->similarity = 5.0;
    $student = m8FaceStudent();
    seedPrivateFaceEnrollment($student, m8FaceStaff());

    postM8FaceCheckIn($this, $student, '/api/mobile/attendance/check-in', [
        'similarity' => 100,
        'score' => 100,
        'embedding' => [0.1, 0.2, 0.3],
        'descriptor' => [1, 2, 3],
        'face_descriptor' => 'abc',
    ])
        ->assertUnprocessable()
        ->assertJson(['message' => 'Face not recognized.']);

    expect($client->compareThresholds)->toBe([90.0])
        ->and(AttendanceListe::count())->toBe(0);
});

test('web multipart live_photo reaches rekognition verification', function () {
    freezeM8FaceTime('09:42:00');
    $client = bindRekognitionFaceVerifier();
    $client->similarity = 96.0;
    $student = m8FaceStudent();
    seedPrivateFaceEnrollment($student, m8FaceStaff());

    postM8FaceCheckIn($this, $student, '/students/attendance/check-in')
        ->assertOk()
        ->assertJsonPath('slot', 'morning');

    expect($client->detectCalls)->toBeGreaterThan(0)
        ->and($client->compareCalls)->toBe(1)
        ->and(AttendanceListe::query()->where('user_id', $student->id)->exists())->toBeTrue();
});

test('web json without live_photo still cannot check in', function () {
    freezeM8FaceTime('09:42:00');
    bindRekognitionFaceVerifier();
    $student = m8FaceStudent();
    seedPrivateFaceEnrollment($student, m8FaceStaff());

    $this->actingAs($student)
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])
        ->postJson('/students/attendance/check-in', [
            'formation_id' => $this->formation->id,
            'attendance_day' => Carbon::now()->toDateString(),
            'verified' => true,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('live_photo');

    expect(AttendanceListe::count())->toBe(0);
});

test('duplicate slot remains protected after a verified match', function () {
    freezeM8FaceTime('09:42:00');
    bindRekognitionFaceVerifier();
    $student = m8FaceStudent();
    seedPrivateFaceEnrollment($student, m8FaceStaff());

    postM8FaceCheckIn($this, $student, '/api/mobile/attendance/check-in')->assertOk();
    $count = AttendanceListe::count();

    postM8FaceCheckIn($this, $student, '/api/mobile/attendance/check-in')
        ->assertStatus(409)
        ->assertJson(['message' => "You've already marked attendance for this slot."]);

    expect(AttendanceListe::count())->toBe($count);
});

test('invalid configured threshold fail-closes as unavailable', function () {
    freezeM8FaceTime('09:42:00');
    $client = new FakeRekognitionClient;
    config([
        'services.rekognition.key' => 'testing-key',
        'services.rekognition.secret' => 'testing-secret',
        'services.rekognition.region' => 'us-east-1',
        'face.min_similarity' => 140,
        'face.enrollment_disk' => 'face_enrollments',
    ]);
    Storage::fake('face_enrollments');
    app()->instance(\App\Services\FaceVerification\RekognitionClient::class, $client);
    app()->forgetInstance(FaceVerificationService::class);

    $student = m8FaceStudent();
    seedPrivateFaceEnrollment($student, m8FaceStaff());

    $result = app(RekognitionFaceVerificationService::class)->verify($student, m8LivePhoto());

    expect($result)->toBe(FaceVerificationResult::Unavailable)
        ->and($client->compareCalls)->toBe(0);

    postM8FaceCheckIn($this, $student, '/api/mobile/attendance/check-in')
        ->assertStatus(503);

    expect(AttendanceListe::count())->toBe(0);
});

test('production binding is unavailable when face++ is not configured', function () {
    config([
        'face_verification.api_key' => '',
        'face_verification.api_secret' => '',
    ]);
    app()->forgetInstance(FaceVerificationService::class);

    expect(app(FaceVerificationService::class))->toBeInstanceOf(
        \App\Services\FaceVerification\UnavailableFaceVerificationService::class
    );
});
