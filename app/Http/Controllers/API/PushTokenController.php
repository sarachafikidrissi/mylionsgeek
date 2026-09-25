<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class PushTokenController extends Controller
{
    /**
     * Save Expo push token and/or iOS APNs VoIP (PushKit) token.
     */
    public function store(Request $request)
    {
        $user = Auth::guard('sanctum')->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'expo_push_token' => 'nullable|string|max:255',
            'apns_voip_token' => 'nullable|string|max:255',
        ]);

        if (empty($validated['expo_push_token']) && empty($validated['apns_voip_token'])) {
            return response()->json([
                'message' => 'Provide expo_push_token and/or apns_voip_token.',
            ], 422);
        }

        try {
            if (! empty($validated['expo_push_token'])) {
                $user->expo_push_token = $validated['expo_push_token'];
            }
            if (! empty($validated['apns_voip_token'])) {
                // PushKit tokens are hex; normalize.
                $user->apns_voip_token = strtolower(preg_replace('/\s+/', '', $validated['apns_voip_token']));
            }
            $user->save();

            Log::info('Push tokens saved', [
                'user_id' => $user->id,
                'has_expo' => ! empty($validated['expo_push_token']),
                'has_voip' => ! empty($validated['apns_voip_token']),
            ]);

            return response()->json([
                'message' => 'Push token saved successfully',
                'success' => true,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to save push token', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to save push token',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
