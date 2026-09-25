<?php

use App\Services\FaceVerification\FacePlusPlusVerificationService;

test('blank face++ credentials are not configured', function () {
    config([
        'face_verification.api_key' => '',
        'face_verification.api_secret' => '',
        'face_verification.api_url' => 'https://api-us.faceplusplus.com/facepp/v3/compare',
    ]);

    expect(FacePlusPlusVerificationService::isConfigured())->toBeFalse();
});

test('face++ is configured when key secret and url are set', function () {
    config([
        'face_verification.api_key' => 'k',
        'face_verification.api_secret' => 's',
        'face_verification.api_url' => 'https://api-us.faceplusplus.com/facepp/v3/compare',
    ]);

    expect(FacePlusPlusVerificationService::isConfigured())->toBeTrue();
});
