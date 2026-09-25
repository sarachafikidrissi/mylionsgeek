<?php

namespace App\Services\FaceVerification;

use App\Exceptions\FaceVerificationException;
use App\Models\User;
use App\Services\FaceVerificationService as FacePlusPlusClient;
use Illuminate\Http\UploadedFile;

/**
 * Attendance check-in matching via Face++ compare against the student's profile photo.
 */
class FacePlusPlusVerificationService implements FaceVerificationService
{
    public function __construct(
        private readonly FacePlusPlusClient $facePlusPlus,
    ) {}

    public static function isConfigured(): bool
    {
        $key = config('face_verification.api_key');
        $secret = config('face_verification.api_secret');
        $url = config('face_verification.api_url');

        return is_string($key) && trim($key) !== ''
            && is_string($secret) && trim($secret) !== ''
            && is_string($url) && trim($url) !== '';
    }

    public function verify(User $user, UploadedFile $livePhoto): FaceVerificationResult
    {
        if (! self::isConfigured()) {
            return FaceVerificationResult::Unavailable;
        }

        try {
            $result = $this->facePlusPlus->verify($user, $livePhoto);
        } catch (FaceVerificationException) {
            return FaceVerificationResult::Unavailable;
        }

        if (! is_array($result) || ! array_key_exists('passed', $result)) {
            return FaceVerificationResult::Unavailable;
        }

        return $result['passed'] ? FaceVerificationResult::Verified : FaceVerificationResult::Rejected;
    }
}
