<?php

namespace Tests\Support;

use App\Models\User;
use App\Services\FaceVerification\FaceVerificationResult;
use App\Services\FaceVerification\FaceVerificationService;
use Illuminate\Http\UploadedFile;

/**
 * Test-only stub. Production binds FacePlusPlusVerificationService when
 * Face++ is configured, otherwise UnavailableFaceVerificationService.
 */
final class VerifiedFaceVerificationService implements FaceVerificationService
{
    public function verify(User $user, UploadedFile $livePhoto): FaceVerificationResult
    {
        return FaceVerificationResult::Verified;
    }
}
