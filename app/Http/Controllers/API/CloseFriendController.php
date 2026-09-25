<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CloseFriend;
use App\Models\Follower;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CloseFriendController extends Controller
{
    private function avatarUrl(?User $u): ?string
    {
        if (!$u || !$u->image) return null;
        $path = ltrim((string) $u->image, '/');
        return str_contains($path, 'img/profile/')
            ? url('storage/' . $path)
            : url('storage/img/profile/' . $path);
    }

    /**
     * GET /api/mobile/close-friends
     *
     * Returns the auth user's close-friend list, plus a candidate list
     * (people they follow) annotated with `is_close`. The mobile client
     * can use both to render an editor screen with one network call.
     */
    public function index()
    {
        $auth = Auth::guard('sanctum')->user();
        if (!$auth) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $closeIds = CloseFriend::where('user_id', $auth->id)
            ->pluck('friend_id')
            ->map(fn($x) => (int) $x)
            ->all();

        $candidateIds = Follower::where('follower_id', $auth->id)
            ->pluck('followed_id')
            ->map(fn($x) => (int) $x)
            ->all();

        $allIds = array_values(array_unique(array_merge($closeIds, $candidateIds)));
        $users = User::whereIn('id', $allIds)->get(['id', 'name', 'image']);

        $byId = $users->keyBy('id');
        $closeSet = array_flip($closeIds);

        $closeFriends = collect($closeIds)
            ->map(fn($id) => $byId->get($id))
            ->filter()
            ->map(fn($u) => [
                'id'       => (int) $u->id,
                'name'     => $u->name,
                'avatar'   => $this->avatarUrl($u),
                'is_close' => true,
            ])
            ->values();

        $candidates = collect($candidateIds)
            ->map(fn($id) => $byId->get($id))
            ->filter()
            ->map(fn($u) => [
                'id'       => (int) $u->id,
                'name'     => $u->name,
                'avatar'   => $this->avatarUrl($u),
                'is_close' => isset($closeSet[(int) $u->id]),
            ])
            ->values();

        return response()->json([
            'close_friends' => $closeFriends,
            'candidates'    => $candidates,
            'total'         => $closeFriends->count(),
        ]);
    }

    /**
     * POST /api/mobile/close-friends/{friendId}
     * Adds a user to the auth user's close-friends list. Idempotent.
     */
    public function store(int $friendId)
    {
        $auth = Auth::guard('sanctum')->user();
        if (!$auth) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if ((int) $auth->id === $friendId) {
            return response()->json(['message' => 'Cannot add yourself'], 422);
        }

        if (in_array($friendId, $auth->excludedAuthorIds(), true)) {
            return response()->json(['message' => 'Not allowed'], 403);
        }

        if (!User::where('id', $friendId)->exists()) {
            return response()->json(['message' => 'User not found'], 404);
        }

        CloseFriend::firstOrCreate([
            'user_id'   => $auth->id,
            'friend_id' => $friendId,
        ]);

        return response()->json([
            'ok' => true,
            'total' => CloseFriend::where('user_id', $auth->id)->count(),
        ]);
    }

    /**
     * DELETE /api/mobile/close-friends/{friendId}
     */
    public function destroy(int $friendId)
    {
        $auth = Auth::guard('sanctum')->user();
        if (!$auth) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        CloseFriend::where('user_id', $auth->id)
            ->where('friend_id', $friendId)
            ->delete();

        return response()->json([
            'ok' => true,
            'total' => CloseFriend::where('user_id', $auth->id)->count(),
        ]);
    }
}
