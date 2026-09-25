<?php

namespace App\Services\FaceVerification;

use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * Fail-closed default when Face++ is not configured.
 * Presence of a valid image is not identity verification.
 */
class UnavailableFaceVerificationService implements FaceVerificationService
{
    public function verify(User $user, UploadedFile $livePhoto): FaceVerificationResult
    {
        return FaceVerificationResult::Unavailable;
    }
}
