<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function bindVerifiedFaceVerifier(): void
{
    app()->instance(
        App\Services\FaceVerification\FaceVerificationService::class,
        new Tests\Support\VerifiedFaceVerificationService
    );
}

function m8LivePhoto(string $name = 'live_photo.jpg'): Illuminate\Http\UploadedFile
{
    return Illuminate\Http\UploadedFile::fake()->image($name);
}

function bindFacePlusPlusVerifier(): void
{
    config([
        'face_verification.api_key' => 'testing-facepp-key',
        'face_verification.api_secret' => 'testing-facepp-secret',
        'face_verification.api_url' => 'https://api-us.faceplusplus.com/facepp/v3/compare',
        'face_verification.threshold' => 80,
    ]);

    Illuminate\Support\Facades\Storage::fake('public');

    app()->forgetInstance(App\Services\FaceVerification\FaceVerificationService::class);
}

function bindRekognitionFaceVerifier(?Tests\Support\FakeRekognitionClient $client = null): Tests\Support\FakeRekognitionClient
{
    $client ??= new Tests\Support\FakeRekognitionClient;

    config([
        'services.rekognition.key' => 'testing-key',
        'services.rekognition.secret' => 'testing-secret',
        'services.rekognition.region' => 'us-east-1',
        'face.min_similarity' => 90,
        'face.enrollment_disk' => 'face_enrollments',
    ]);

    Illuminate\Support\Facades\Storage::fake('face_enrollments');
    Illuminate\Support\Facades\Storage::fake('public');

    app()->instance(App\Services\FaceVerification\RekognitionClient::class, $client);
    app()->forgetInstance(App\Services\FaceVerification\FaceVerificationService::class);
    app()->instance(
        App\Services\FaceVerification\FaceVerificationService::class,
        app()->make(App\Services\FaceVerification\RekognitionFaceVerificationService::class)
    );

    return $client;
}
