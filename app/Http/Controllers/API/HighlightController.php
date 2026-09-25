<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\Concerns\ServesStoryMedia;
use App\Http\Controllers\Controller;
use App\Models\Story;
use App\Models\StoryHighlight;
use App\Models\StoryHighlightItem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

class HighlightController extends Controller
{
    use ServesStoryMedia;

    private const HIGHLIGHTS_DIR = 'highlights';

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────
    private function mapHighlight(StoryHighlight $h, int $authUserId, bool $includeStories = false): array
    {
        $payload = [
            'id'           => (int) $h->id,
            'user_id'      => (int) $h->user_id,
            'title'        => (string) $h->title,
            'cover_url'    => $h->cover_path ? $this->publicUrl($h->cover_path) : null,
            'stories_count'=> (int) ($h->stories_count ?? $h->items->count() ?? 0),
            'is_mine'      => (int) $h->user_id === $authUserId,
            'created_at'   => optional($h->created_at)->toIso8601String(),
            'updated_at'   => optional($h->updated_at)->toIso8601String(),
        ];

        if ($includeStories) {
            $payload['stories'] = $h->stories->map(function (Story $s) use ($authUserId, $h) {
                $isOwner = (int) $h->user_id === $authUserId;
                $overlays = $this->overlaysForViewer($s, $authUserId);
                $interactions = $s->relationLoaded('interactions') ? $s->interactions : collect();

                return [
                    'id'          => (int) $s->id,
                    'media_url'   => $this->mediaUrl($s, $authUserId),
                    'media_type'  => $s->media_type,
                    'duration_ms' => (int) ($s->duration_ms ?? 5000),
                    'width'       => $s->width,
                    'height'      => $s->height,
                    'created_at'  => optional($s->created_at)->toIso8601String(),
                    'overlays'    => array_values($overlays),
                    'interactions'=> $interactions->map(function ($i) use ($authUserId, $isOwner) {
                        $payload = is_array($i->payload) ? $i->payload : [];
                        $correct = $payload['correct_index'] ?? null;
                        if (!$isOwner) {
                            unset($payload['correct_index']);
                        }
                        $my = null;
                        foreach ($i->responses ?? [] as $r) {
                            if ((int) $r->user_id === $authUserId) {
                                $my = $r->payload;
                                break;
                            }
                        }
                        $out = [
                            'overlay_id' => $i->overlay_id,
                            'type' => $i->type,
                            'payload' => $payload,
                            'my_response' => $my,
                            'responses_count' => $i->responses ? $i->responses->count() : 0,
                        ];
                        if ($i->type === 'quiz' && is_array($my) && $correct !== null) {
                            $out['is_correct'] = ((int) ($my['index'] ?? -1)) === (int) $correct;
                        }
                        return $out;
                    })->values(),
                ];
            })->values();
        }

        return $payload;
    }

    /**
     * Copies a story's media file into the highlights folder so the cover
     * remains valid even if the source story is later removed from the
     * highlight or auto-purged (when the highlight gets emptied).
     */
    private function copyStoryMediaForCover(Story $story): ?string
    {
        try {
            if (!$story->media_path) return null;
            $relative = ltrim((string) $story->media_path, '/');
            $contents = null;
            foreach (['public', self::STORIES_PRIVATE_DISK] as $disk) {
                if (Storage::disk($disk)->exists($relative)) {
                    $contents = Storage::disk($disk)->get($relative);
                    break;
                }
            }
            if ($contents === null) return null;
            if (($story->audience ?: 'public') === 'close_friends') {
                return null;
            }

            $ext = pathinfo($relative, PATHINFO_EXTENSION) ?: 'jpg';
            $dest = self::HIGHLIGHTS_DIR . '/cover_' . $story->user_id . '_' . time() . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
            Storage::disk('public')->put($dest, $contents);
            return $dest;
        } catch (Throwable $e) {
            Log::warning('Highlight cover copy failed: ' . $e->getMessage());
            return null;
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // GET /api/mobile/users/{userId}/highlights
    // List a user's highlights (ordered most-recently-updated first).
    // ──────────────────────────────────────────────────────────────────
    public function indexForUser(int $userId)
    {
        $auth = Auth::guard('sanctum')->user();
        if (!$auth) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if (!User::where('id', $userId)->exists()) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $isOwn = (int) $userId === (int) $auth->id;

        $highlights = StoryHighlight::where('user_id', $userId)
            ->withCount(['items as stories_count' => function ($q) use ($auth, $isOwn) {
                if ($isOwn) {
                    return;
                }
                $q->whereHas('story', function ($s) use ($auth) {
                    $s->where(function ($w) use ($auth) {
                        $w->where('stories.audience', 'public')
                          ->orWhere(function ($qq) use ($auth) {
                              $qq->where('stories.audience', 'close_friends')
                                 ->whereExists(function ($e) use ($auth) {
                                     $e->selectRaw('1')
                                       ->from('close_friends')
                                       ->whereColumn('close_friends.user_id', 'stories.user_id')
                                       ->where('close_friends.friend_id', $auth->id);
                                 });
                          });
                    });
                });
            }])
            ->orderByDesc('updated_at')
            ->get();

        $list = $highlights
            ->filter(fn($h) => $h->stories_count > 0 || (int) $h->user_id === (int) $auth->id) // hide empty ones from others
            ->map(fn($h) => $this->mapHighlight($h, $auth->id))
            ->values();

        return response()->json(['highlights' => $list]);
    }

    // ──────────────────────────────────────────────────────────────────
    // GET /api/mobile/highlights/{id}
    // Fetch a highlight + all its stories (for the highlight viewer).
    // ──────────────────────────────────────────────────────────────────
    public function show(int $id)
    {
        $auth = Auth::guard('sanctum')->user();
        if (!$auth) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $highlight = StoryHighlight::with([
            'stories' => function ($q) {
                $q->orderBy('story_highlight_items.position');
            },
            'stories.interactions.responses',
        ])->find($id);

        if (!$highlight) {
            return response()->json(['message' => 'Highlight not found'], 404);
        }

        $highlight->loadCount('items as stories_count');

        $visibleStories = $highlight->stories
            ->filter(function (Story $s) use ($auth, $highlight) {
                if ((int) $highlight->user_id === (int) $auth->id) {
                    return true;
                }

                return $s->isVisibleTo($auth);
            })
            ->values();
        $highlight->setRelation('stories', $visibleStories);

        return response()->json([
            'highlight' => $this->mapHighlight($highlight, $auth->id, true),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // POST /api/mobile/highlights
    // Body: { title: string, story_id: int }
    // Creates a new highlight seeded with the given story.
    // ──────────────────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $auth = Auth::guard('sanctum')->user();
        if (!$auth) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $validator = Validator::make($request->all(), [
            'title'    => ['required', 'string', 'min:1', 'max:80'],
            'story_id' => ['required', 'integer', 'exists:stories,id'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid highlight', 'errors' => $validator->errors()], 422);
        }

        $story = Story::find($request->input('story_id'));
        if (!$story || (int) $story->user_id !== (int) $auth->id) {
            return response()->json(['message' => 'You can only highlight your own stories'], 403);
        }

        try {
            $coverPath = $this->copyStoryMediaForCover($story);

            $highlight = StoryHighlight::create([
                'user_id'    => $auth->id,
                'title'      => trim((string) $request->input('title')),
                'cover_path' => $coverPath,
            ]);

            StoryHighlightItem::create([
                'highlight_id' => $highlight->id,
                'story_id'     => $story->id,
                'position'     => 0,
            ]);

            $highlight->loadCount('items as stories_count');

            return response()->json([
                'highlight' => $this->mapHighlight($highlight, $auth->id),
            ], 201);
        } catch (Throwable $e) {
            Log::error('Highlight create failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'Could not create highlight'], 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // POST /api/mobile/highlights/{id}/stories
    // Body: { story_id: int }
    // Adds a story to an existing highlight (idempotent).
    // ──────────────────────────────────────────────────────────────────
    public function addStory(int $id, Request $request)
    {
        $auth = Auth::guard('sanctum')->user();
        if (!$auth) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $validator = Validator::make($request->all(), [
            'story_id' => ['required', 'integer', 'exists:stories,id'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid request', 'errors' => $validator->errors()], 422);
        }

        $highlight = StoryHighlight::find($id);
        if (!$highlight) return response()->json(['message' => 'Highlight not found'], 404);
        if ((int) $highlight->user_id !== (int) $auth->id) {
            return response()->json(['message' => 'Not your highlight'], 403);
        }

        $story = Story::find($request->input('story_id'));
        if (!$story || (int) $story->user_id !== (int) $auth->id) {
            return response()->json(['message' => 'You can only add your own stories'], 403);
        }

        $maxPos = StoryHighlightItem::where('highlight_id', $highlight->id)->max('position') ?? -1;
        StoryHighlightItem::firstOrCreate(
            ['highlight_id' => $highlight->id, 'story_id' => $story->id],
            ['position' => $maxPos + 1]
        );

        $highlight->touch();
        $highlight->loadCount('items as stories_count');

        return response()->json([
            'ok'        => true,
            'highlight' => $this->mapHighlight($highlight, $auth->id),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // DELETE /api/mobile/highlights/{id}/stories/{storyId}
    // Removes a story from a highlight. If the highlight becomes empty
    // we delete it.
    // ──────────────────────────────────────────────────────────────────
    public function removeStory(int $id, int $storyId)
    {
        $auth = Auth::guard('sanctum')->user();
        if (!$auth) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $highlight = StoryHighlight::find($id);
        if (!$highlight) return response()->json(['message' => 'Highlight not found'], 404);
        if ((int) $highlight->user_id !== (int) $auth->id) {
            return response()->json(['message' => 'Not your highlight'], 403);
        }

        StoryHighlightItem::where('highlight_id', $highlight->id)
            ->where('story_id', $storyId)
            ->delete();

        $remaining = StoryHighlightItem::where('highlight_id', $highlight->id)->count();
        if ($remaining === 0) {
            $this->destroyInternal($highlight);
            return response()->json(['ok' => true, 'deleted' => true]);
        }

        $highlight->touch();
        $highlight->loadCount('items as stories_count');

        return response()->json([
            'ok'        => true,
            'highlight' => $this->mapHighlight($highlight, $auth->id),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // PATCH /api/mobile/highlights/{id}
    // Body: { title?: string, cover_story_id?: int }
    // ──────────────────────────────────────────────────────────────────
    public function update(int $id, Request $request)
    {
        $auth = Auth::guard('sanctum')->user();
        if (!$auth) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $highlight = StoryHighlight::find($id);
        if (!$highlight) return response()->json(['message' => 'Highlight not found'], 404);
        if ((int) $highlight->user_id !== (int) $auth->id) {
            return response()->json(['message' => 'Not your highlight'], 403);
        }

        $validator = Validator::make($request->all(), [
            'title'          => ['nullable', 'string', 'min:1', 'max:80'],
            'cover_story_id' => ['nullable', 'integer', 'exists:stories,id'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid update', 'errors' => $validator->errors()], 422);
        }

        if ($request->filled('title')) {
            $highlight->title = trim((string) $request->input('title'));
        }
        if ($request->filled('cover_story_id')) {
            $coverStory = Story::find($request->input('cover_story_id'));
            if ($coverStory && (int) $coverStory->user_id === (int) $auth->id) {
                $newCover = $this->copyStoryMediaForCover($coverStory);
                if ($newCover) {
                    if ($highlight->cover_path) {
                        try { Storage::disk('public')->delete($highlight->cover_path); } catch (Throwable $e) {}
                    }
                    $highlight->cover_path = $newCover;
                }
            }
        }
        $highlight->save();
        $highlight->loadCount('items as stories_count');

        return response()->json([
            'highlight' => $this->mapHighlight($highlight, $auth->id),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // DELETE /api/mobile/highlights/{id}
    // ──────────────────────────────────────────────────────────────────
    public function destroy(int $id)
    {
        $auth = Auth::guard('sanctum')->user();
        if (!$auth) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $highlight = StoryHighlight::find($id);
        if (!$highlight) return response()->json(['message' => 'Highlight not found'], 404);
        if ((int) $highlight->user_id !== (int) $auth->id) {
            return response()->json(['message' => 'Not your highlight'], 403);
        }

        $this->destroyInternal($highlight);
        return response()->json(['ok' => true]);
    }

    private function destroyInternal(StoryHighlight $highlight): void
    {
        try {
            if ($highlight->cover_path) {
                Storage::disk('public')->delete($highlight->cover_path);
            }
        } catch (Throwable $e) {
            Log::warning('Highlight cover delete failed: ' . $e->getMessage());
        }
        $highlight->delete();
    }
}
