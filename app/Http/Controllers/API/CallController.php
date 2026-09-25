<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Services\AblyCapabilityService;
use App\Services\CallService;
use Ably\AblyRest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class CallController extends Controller
{
    public function __construct(
        protected CallService $callService
    ) {
    }

    private function callPayload(Call $call): array
    {
        $call->loadMissing(['caller:id,name,image', 'callee:id,name,image']);

        return [
            'id' => $call->id,
            'channel_name' => $call->channel_name,
            'type' => $call->type ?? Call::TYPE_AUDIO,
            'status' => $call->status,
            'uuid' => $call->voip_uuid,
            'started_at' => $call->started_at,
            'answered_at' => $call->answered_at,
            'ended_at' => $call->ended_at,
            'duration' => $call->duration,
            'caller' => $call->caller,
            'callee' => $call->callee,
        ];
    }

    /**
     * Start a call to callee_id. Returns call id, channel name, and Agora token for caller.
     */
    public function initiate(Request $request): JsonResponse
    {
        $request->validate([
            'callee_id' => ['required_without:receiver_id', 'nullable', 'integer', 'exists:users,id'],
            'receiver_id' => ['required_without:callee_id', 'nullable', 'integer', 'exists:users,id'],
            'type' => ['sometimes', 'string', 'in:audio,video'],
        ]);

        $calleeId = (int) ($request->input('callee_id') ?: $request->input('receiver_id'));
        $type = $request->input('type', Call::TYPE_AUDIO);

        try {
            $result = $this->callService->initiate(Auth::user(), $calleeId, $type);
            $call = $result['call'];

            return response()->json([
                'call_id' => $call->id,
                'channel_name' => $result['channel_name'],
                'token' => $result['token'],
                'app_id' => $result['app_id'],
                'uid' => (int) Auth::id(),
                'type' => $call->type,
                'call' => $this->callPayload($call),
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            Log::warning('Call initiate failed: '.$e->getMessage());

            return response()->json(['message' => $e->getMessage()], 500);
        } catch (\Throwable $e) {
            Log::error('Call initiate error: '.$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'message' => 'Unable to start call. Check server logs.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function accept(int $id): JsonResponse
    {
        $call = Call::findOrFail($id);
        Gate::authorize('accept', $call);

        try {
            $result = $this->callService->accept($id, Auth::user());
            $call = $result['call'];

            return response()->json([
                'call_id' => $call->id,
                'channel_name' => $result['channel_name'],
                'token' => $result['token'],
                'app_id' => $result['app_id'],
                'uid' => (int) Auth::id(),
                'type' => $call->type,
                'call' => $this->callPayload($call),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function reject(int $id): JsonResponse
    {
        $call = Call::findOrFail($id);
        Gate::authorize('reject', $call);

        try {
            $this->callService->reject($id, Auth::user());

            return response()->json(['message' => 'Call rejected.']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function cancel(int $id): JsonResponse
    {
        $call = Call::findOrFail($id);
        Gate::authorize('cancel', $call);

        try {
            $this->callService->cancel($id, Auth::user());

            return response()->json(['message' => 'Call cancelled.']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function end(int $id): JsonResponse
    {
        $call = Call::findOrFail($id);
        Gate::authorize('end', $call);

        try {
            $this->callService->end($id, Auth::user());

            return response()->json(['message' => 'Call ended.']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Fresh Agora RTC token for a participant of an active call.
     */
    public function token(int $id): JsonResponse
    {
        $call = Call::findOrFail($id);
        Gate::authorize('token', $call);

        try {
            $result = $this->callService->token($id, Auth::user());

            return response()->json($result);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get a single call (participant only). Pending incoming for push cold-open.
     */
    public function show(int $id): JsonResponse
    {
        $call = Call::with(['caller:id,name,image', 'callee:id,name,image'])->find($id);
        if (! $call) {
            return response()->json(['message' => 'Call not found or expired.'], 404);
        }

        Gate::authorize('view', $call);

        // Push cold-open for callee while ringing.
        if ($call->isRinging() && (int) $call->callee_id === (int) Auth::id()) {
            return response()->json([
                'call_id' => $call->id,
                'channel_name' => $call->channel_name,
                'type' => $call->type ?? Call::TYPE_AUDIO,
                'call_type' => $call->type ?? Call::TYPE_AUDIO,
                'status' => $call->status,
                'uuid' => $call->voip_uuid,
                'caller' => [
                    'id' => $call->caller->id,
                    'name' => $call->caller->name,
                    'avatar' => $call->caller->image,
                ],
            ]);
        }

        return response()->json([
            'call_id' => $call->id,
            'call' => $this->callPayload($call),
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $perPage = (int) $request->get('per_page', 20);
        $calls = $this->callService->history(Auth::user(), min($perPage, 50));

        return response()->json($calls);
    }

    public function getAblyToken(AblyCapabilityService $capabilities): JsonResponse
    {
        $user = Auth::user();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        try {
            $ablyKey = config('services.ably.key');
            if (! $ablyKey) {
                return response()->json(['error' => 'Ably not configured'], 500);
            }
            $tokenRequest = [
                'capability' => $capabilities->encode($capabilities->callCapabilities($user)),
                'clientId' => (string) $user->id,
            ];
            $ably = new AblyRest($ablyKey);
            $tokenDetails = $ably->auth->requestToken($tokenRequest);

            return response()->json([
                'token' => $tokenDetails->token,
                'expires' => $tokenDetails->expires,
                'clientId' => (string) $user->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Call Ably token failed: '.$e->getMessage());
            return response()->json(['error' => 'Failed to generate token'], 500);
        }
    }
}
