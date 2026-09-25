<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Face verification (attendance check-in)
    |--------------------------------------------------------------------------
    |
    | Attendance check-in uses Face++ compare against the student's profile
    | photo (FACEPP_* in config/face_verification.php). Missing Face++ keys
    | fail-close verification (HTTP 503).
    |
    | FACE_VERIFICATION_REQUIRED=false skips Face++ (local/dev without keys).
    | Keep true in production.
    |
    | FACE_VERIFICATION_MIN_SIMILARITY is only used by optional Rekognition
    | staff enrollment, not by Face++ check-in.
    |
    */

    'required' => filter_var(env('FACE_VERIFICATION_REQUIRED', true), FILTER_VALIDATE_BOOLEAN),

    'min_similarity' => env('FACE_VERIFICATION_MIN_SIMILARITY', 90),

    'min_face_confidence' => 90.0,

    'min_brightness' => 20.0,

    'min_sharpness' => 20.0,

    'enrollment_disk' => env('FACE_ENROLLMENT_DISK', 'face_enrollments'),

];
