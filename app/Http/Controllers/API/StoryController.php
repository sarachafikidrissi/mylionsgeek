<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\Concerns\ServesStoryMedia;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Story;
use App\Models\StoryInteraction;
use App\Models\StoryInteractionResponse;
use App\Models\StoryReaction;
use App\Models\StoryNotification;
use App\Models\StoryReport;
use App\Models\StoryReportNotification;
use App\Services\ExpoPushNotificationService;
use App\Models\StoryView;
use App\Models\User;
use App\Models\UserBlock;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

class StoryController extends Controller
{
    use ServesStoryMedia;

    private const TTL_HOURS = 24;
    private const MAX_PHOTO_BYTES = 10 * 1024 * 1024;  // 10 MB
    private const MAX_VIDEO_BYTES = 50 * 1024 * 1024;  // 50 MB
    private const MAX_VIDEO_DURATION_MS = 60_000;       // 60s

    private function storyDiskForAudience(?string $audience): string
    {
        return ($audience ?: 'public') === 'close_friends'
            ? self::STORIES_PRIVATE_DISK
            : 'public';
    }

    /**
     * Authorize a signed stream request: viewer must still be allowed to see
     * the story. Non-owners lose access after hide, close-friend removal, or expiry.
     */
    private function authorizeSignedStoryStream(Story $story, Request $request): User
    {
        $viewerId = (int) $request->query('viewer', 0);
        $viewer = $viewerId > 0 ? User::query()->find($viewerId) : null;
        if (! $viewer || ! $story->isVisibleTo($viewer)) {
            abort(404);
        }

        $isOwner = (int) $story->user_id === (int) $viewer->id;
        if (! $isOwner && $story->isExpired()) {
            abort(404);
        }

        return $viewer;
    }

    private function resolveStoryMediaDisk(string $relative): ?string
    {
        $relative = ltrim($relative, '/');
        foreach ([self::STORIES_PRIVATE_DISK, 'public'] as $disk) {
            if (Storage::disk($disk)->exists($relative)) {
                return $disk;
            }
        }

        return null;
    }

    private function copyStoryMedia(string $src, string $dest, string $destDisk = 'public'): bool
    {
        $srcDisk = $this->resolveStoryMediaDisk($src);
        if (! $srcDisk) {
            return false;
        }
        Storage::disk($destDisk)->put($dest, Storage::disk($srcDisk)->get(ltrim($src, '/')));

        return true;
    }

    private function deleteStoryMediaFile(?string $path): void
    {
        if (!$path) {
            return;
        }
        $relative = ltrim($path, '/');
        foreach (['public', self::STORIES_PRIVATE_DISK] as $disk) {
            try {
                if (Storage::disk($disk)->exists($relative)) {
                    Storage::disk($disk)->delete($relative);
                }
            } catch (Throwable $e) {
                Log::warning('Story media delete failed on '.$disk.': '.$e->getMessage());
            }
        }
    }

    /**
     * Stream story media via temporary signed URL (close-friends privacy).
     * Signature alone is not enough — visibility is re-checked on every hit.
     * Migrates legacy public-disk close-friends files onto the private disk.
     */
    public function streamMedia(Request $request, int $story)
    {
        $model = Story::query()->find($story);
        if (!$model || !$model->media_path) {
            abort(404);
        }

        $this->authorizeSignedStoryStream($model, $request);

        $relative = ltrim((string) $model->media_path, '/');
        $isCloseFriends = ($model->audience ?: 'public') === 'close_friends';

        if (Storage::disk(self::STORIES_PRIVATE_DISK)->exists($relative)) {
            return Storage::disk(self::STORIES_PRIVATE_DISK)->response($relative);
        }

        if (Storage::disk('public')->exists($relative)) {
            if ($isCloseFriends) {
                try {
                    Storage::disk(self::STORIES_PRIVATE_DISK)->put(
                        $relative,
                        Storage::disk('public')->get($relative)
                    );
                    Storage::disk('public')->delete($relative);
                    return Storage::disk(self::STORIES_PRIVATE_DISK)->response($relative);
                } catch (Throwable $e) {
                    Log::warning('Close-friends media migrate failed: '.$e->getMessage());
                }
            }

            return Storage::disk('public')->response($relative);
        }

        abort(404);
    }

    /**
     * Lazy cleanup of expired stories. Runs at most once per request to keep
     * the DB tidy without needing a cron. Deletes the rows and the files.
     * Highlighted stories are kept (see Story::purgeExpired).
     */
    private function purgeExpired(): void
    {
        try {
            Story::purgeExpired(50);
        } catch (Throwable $e) {
            Log::warning('Story lazy purge failed: ' . $e->getMessage());
        }
    }

    /**
     * Active story the viewer is allowed to see. Returns 404 (not 403) so
     * close-friends stories do not leak their existence.
     */
    private function findVisibleActiveStory(int $id, User $user): ?Story
    {
        $story = Story::active()->find($id);
        if (!$story || !$story->isVisibleTo($user)) {
            return null;
        }

        return $story;
    }

    private function extensionForMime(string $mime, string $type): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/heic' => 'heic',
            'image/heif' => 'heif',
            'image/gif' => 'gif',
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
            'video/x-matroska' => 'mkv',
            default => $type === 'video' ? 'mp4' : 'jpg',
        };
    }

    private function overlayMeasuredSize(array $o): array
    {
        $out = [];
        $mw = (float) ($o['measured_width'] ?? $o['_measuredWidth'] ?? 0);
        $mh = (float) ($o['measured_height'] ?? $o['_measuredHeight'] ?? 0);
        if ($mw > 0) {
            $out['measured_width'] = min(4000.0, $mw);
        }
        if ($mh > 0) {
            $out['measured_height'] = min(4000.0, $mh);
        }

        return $out;
    }

    private function avatarUrl(?User $u): ?string
    {
        if (!$u || !$u->image) return null;
        $path = ltrim((string) $u->image, '/');
        return str_contains($path, 'img/profile/')
            ? url('storage/' . $path)
            : url('storage/img/profile/' . $path);
    }

    private function mapStory(Story $s, int $authUserId, ?array $repostStoryIds = null): array
    {
        $canRepost = false;
        $isCloseFriends = ($s->audience ?: 'public') === 'close_friends';
        if ($authUserId > 0 && (int) $s->user_id !== $authUserId && ! $isCloseFriends) {
            if (is_array($repostStoryIds)) {
                $canRepost = !empty($repostStoryIds[(int) $s->id]);
            } else {
                $canRepost = DB::table('story_mentions')
                    ->where('story_id', $s->id)
                    ->where('mentioned_user_id', $authUserId)
                    ->exists();
            }
        }

        return [
            'id'                     => (int) $s->id,
            'media_url'              => $this->mediaUrl($s, $authUserId),
            'media_type'             => $s->media_type,
            'audience'               => $s->audience ?: 'public',
            'overlays'               => $this->overlaysForViewer($s, $authUserId),
            'duration_ms'            => (int) ($s->duration_ms ?? 5000),
            'width'                  => $s->width,
            'height'                 => $s->height,
            'created_at'             => optional($s->created_at)->toIso8601String(),
            'expires_at'             => optional($s->expires_at)->toIso8601String(),
            'is_mine'                => (int) $s->user_id === $authUserId,
            'bg_color'               => $s->bg_color,
            'is_hidden'              => (bool) $s->is_hidden,
            'views_count'            => (int) ($s->views_count ?? 0),
            'has_viewed'             => (bool) ($s->viewer_has_seen ?? false),
            'reactions_count'        => (int) ($s->reactions_count ?? 0),
            'my_reaction'            => $s->viewer_reaction ?: null,
            'can_repost_as_mention'  => $canRepost,
            'interactions'           => $this->mapInteractionsForViewer($s, $authUserId),
        ];
    }

    /**
     * Sync story_mentions from overlay JSON (trusted after sanitization).
     */
    private function syncStoryMentionsFromOverlays(Story $story, ?array $overlays): void
    {
        if (!is_array($overlays)) {
            return;
        }
        foreach ($overlays as $o) {
            if (!is_array($o) || ($o['type'] ?? '') !== 'mention') {
                continue;
            }
            $uid = (int) ($o['user_id'] ?? 0);
            if ($uid <= 0 || !User::query()->where('id', $uid)->exists()) {
                continue;
            }
            try {
                DB::table('story_mentions')->insertOrIgnore([
                    'story_id'           => $story->id,
                    'mentioned_user_id'  => $uid,
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ]);
            } catch (Throwable $e) {
                Log::warning('story_mentions insert failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Overlays copied to a mention-repost story (strip @mentions).
     */
    private function overlaysForMentionRepost(?array $overlays): ?array
    {
        if (!is_array($overlays) || $overlays === []) {
            return null;
        }
        $out = [];
        foreach ($overlays as $o) {
            if (!is_array($o) || ($o['type'] ?? '') === 'mention') {
                continue;
            }
            $out[] = $o;
        }

        return $out === [] ? null : array_values($out);
    }

    /**
     * GET /api/mobile/stories
     *
     * Returns active stories grouped by user. The authed user's own group
     * is always first (so the "Your story" entry has data). Each entry:
     *   { user: {id, name, avatar}, stories: [...], has_unseen: bool, latest_at: ts }
     */
    public function index(Request $request)
    {
        $this->purgeExpired();

        $user = Auth::guard('sanctum')->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        try {
            // Fetch all active stories with their owner and a view-count subquery
            // and a flag for whether the current user has viewed each story.
            $excluded = $user->excludedAuthorIds();

            $stories = Story::query()
                ->with(['user:id,name,image', 'interactions.responses'])
                ->withCount('views as views_count')
                ->withCount('reactions as reactions_count')
                ->selectSub(function ($q) use ($user) {
                    $q->selectRaw('COUNT(*)')
                        ->from('story_views')
                        ->whereColumn('story_views.story_id', 'stories.id')
                        ->where('story_views.user_id', $user->id);
                }, 'viewer_has_seen')
                ->selectSub(function ($q) use ($user) {
                    $q->select('emoji')
                        ->from('story_reactions')
                        ->whereColumn('story_reactions.story_id', 'stories.id')
                        ->where('story_reactions.user_id', $user->id)
                        ->limit(1);
                }, 'viewer_reaction')
                ->where('expires_at', '>', now())
                ->where(function ($q) use ($user, $excluded) {
                    $q->where('stories.user_id', $user->id)
                      ->orWhere(function ($visible) use ($user, $excluded) {
                          $visible->where('stories.is_hidden', false)
                              ->where(function ($aud) use ($user) {
                                  $aud->where('stories.audience', 'public')
                                      ->orWhere(function ($qq) use ($user) {
                                          $qq->where('stories.audience', 'close_friends')
                                             ->whereExists(function ($e) use ($user) {
                                                 $e->select(DB::raw(1))
                                                   ->from('close_friends')
                                                   ->whereColumn('close_friends.user_id', 'stories.user_id')
                                                   ->where('close_friends.friend_id', $user->id);
                                             });
                                      });
                              });
                          if ($excluded !== []) {
                              $visible->whereNotIn('stories.user_id', $excluded);
                          }
                      });
                })
                ->orderBy('user_id')
                ->orderBy('created_at')
                ->get();

            $repostStoryIds = [];
            if ($stories->isNotEmpty()) {
                $ids = $stories->pluck('id')->all();
                $rows = DB::table('story_mentions')
                    ->whereIn('story_id', $ids)
                    ->where('mentioned_user_id', $user->id)
                    ->pluck('story_id');
                foreach ($rows as $sid) {
                    $repostStoryIds[(int) $sid] = true;
                }
            }

            // Group by user.
            $groups = $stories->groupBy('user_id')->map(function ($group) use ($user, $repostStoryIds) {
                $owner = $group->first()->user;
                $latest = $group->max('created_at');
                $hasUnseen = $group->contains(function ($s) {
                    return !$s->viewer_has_seen;
                });

                return [
                    'user' => [
                        'id'     => (int) $owner->id,
                        'name'   => $owner->name,
                        'avatar' => $this->avatarUrl($owner),
                    ],
                    'has_unseen' => $hasUnseen,
                    'has_close_friends' => $group->contains(fn ($s) => ($s->audience ?: 'public') === 'close_friends'),
                    'latest_at'  => $latest ? Carbon::parse($latest)->toIso8601String() : null,
                    'stories'    => $group->map(fn ($s) => $this->mapStory($s, $user->id, $repostStoryIds))->values(),
                ];
            })->values();

            // Sort: own group first, then unseen first, then newest first.
            $sorted = $groups->sortBy([
                fn ($a, $b) => ((int) $b['user']['id'] === (int) $user->id ? 1 : 0)
                            <=> ((int) $a['user']['id'] === (int) $user->id ? 1 : 0),
                fn ($a, $b) => ($b['has_unseen'] ? 1 : 0) <=> ($a['has_unseen'] ? 1 : 0),
                fn ($a, $b) => strcmp($b['latest_at'] ?? '', $a['latest_at'] ?? ''),
            ])->values();

            return response()->json(['groups' => $sorted]);
        } catch (Throwable $e) {
            Log::error('Stories index error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'message' => 'Failed to load stories',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * POST /api/mobile/stories
     *
     * multipart/form-data:
     *   media (file, required)         – image (jpg/png/webp/heic) or video (mp4/mov/webm)
     *   media_type (string, required)  – "image" or "video"
     *   duration_ms (int, optional)    – defaults: image=5000, video=actual length / 15s fallback
     *   width, height (optional)
     */
    public function store(Request $request)
    {
        $user = Auth::guard('sanctum')->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $isTextStory = $request->boolean('text_story');

        $validator = Validator::make($request->all(), [
            'media'       => [$isTextStory ? 'nullable' : 'required', 'file'],
            'media_type'  => ['required', 'in:image,video'],
            'duration_ms' => ['nullable', 'integer', 'min:1000', 'max:' . self::MAX_VIDEO_DURATION_MS],
            'width'       => ['nullable', 'integer', 'min:1', 'max:10000'],
            'height'      => ['nullable', 'integer', 'min:1', 'max:10000'],
            'audience'    => ['nullable', 'in:public,close_friends'],
            'bg_color'    => ['nullable', 'string', 'max:16'],
            'text_story'  => ['nullable'],
            'overlays'    => ['nullable', 'string', 'max:400000'],
            'audio'       => ['nullable', 'file', 'max:8192'],
            'boomerang'   => ['nullable', 'array', 'max:12'],
            'boomerang.*' => ['file', 'max:4096'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Invalid story upload',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $file = $request->file('media');
        $type = $isTextStory ? 'image' : $request->input('media_type');
        $bgColor = $this->sanitizeHexColor($request->input('bg_color'));
        $audience = $request->input('audience', 'public');
        $disk = $this->storyDiskForAudience($audience);

        $path = null;
        if ($isTextStory) {
            $path = $this->writeSolidPng($user->id, $bgColor ?: '#111111', $disk);
        } else {
            if (!$file) {
                return response()->json(['message' => 'Media file is required'], 422);
            }
            $maxBytes = $type === 'video' ? self::MAX_VIDEO_BYTES : self::MAX_PHOTO_BYTES;
            if ($file->getSize() > $maxBytes) {
                return response()->json([
                    'message' => 'File is too large. Max ' . ($maxBytes / 1024 / 1024) . ' MB.',
                ], 413);
            }

            $mime = strtolower((string) $file->getMimeType());
            $okImage = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];
            $okVideo = ['video/mp4', 'video/quicktime', 'video/webm', 'video/x-matroska'];
            $allowed = $type === 'video' ? $okVideo : $okImage;
            if (!in_array($mime, $allowed, true)) {
                return response()->json([
                    'message' => 'Unsupported media type: ' . $mime,
                ], 415);
            }

            $ext = $this->extensionForMime($mime, $type);
            $filename = 'story_' . $user->id . '_' . time() . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
            $path = $file->storeAs(self::STORIES_DIR, $filename, $disk);
        }

        try {

            $audioUrl = $this->storeUserStoryAudio($request, (int) $user->id, $disk);
            $stickerUrls = $this->storeNamedImageUploads($request, (int) $user->id, 'sticker_', $disk);
            $layoutCellUrls = $this->storeNamedImageUploads($request, (int) $user->id, 'layout_cell_', $disk);
            $boomerangUrls = $this->storeBoomerangUploads($request, (int) $user->id, $disk);

            $overlays = $this->sanitizeOverlaysPayload(
                $request->input('overlays'),
                $audioUrl,
                $stickerUrls,
                $layoutCellUrls,
                $boomerangUrls
            );

            $story = Story::create([
                'user_id'     => $user->id,
                'media_path'  => $path,
                'media_type'  => $type,
                'audience'    => $audience,
                'bg_color'    => $bgColor,
                'overlays'    => $overlays,
                'duration_ms' => $type === 'video'
                    ? max(1000, min(self::MAX_VIDEO_DURATION_MS, (int) $request->input('duration_ms', 15000)))
                    : 5000,
                'width'       => $request->input('width'),
                'height'      => $request->input('height'),
                'expires_at'  => now()->addHours(self::TTL_HOURS),
                'is_hidden'   => false,
            ]);

            $this->syncStoryMentionsFromOverlays($story, $overlays);
            $this->notifyStoryMentions($story, $user, $overlays);
            $this->persistInteractiveOverlays($story, $overlays);
            $this->persistHashtags($story, $overlays);

            $story->load(['interactions']);
            $story->loadCount(['views as views_count', 'reactions as reactions_count']);
            $story->viewer_has_seen = 0;
            $story->viewer_reaction = null;

            return response()->json([
                'story' => $this->mapStory($story, $user->id, []),
            ], 201);
        } catch (Throwable $e) {
            Log::error('Story store failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'message' => 'Could not upload story.',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * POST /api/mobile/stories/{id}/view
     * Records that the authenticated user has viewed this story. Idempotent.
     */
    public function view(int $id)
    {
        $user = Auth::guard('sanctum')->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $story = $this->findVisibleActiveStory($id, $user);
        if (!$story) {
            return response()->json(['message' => 'Story not found or expired'], 404);
        }

        // Don't record self-views.
        if ((int) $story->user_id === (int) $user->id) {
            return response()->json(['ok' => true, 'self' => true]);
        }

        try {
            DB::table('story_views')->updateOrInsert(
                ['story_id' => $story->id, 'user_id' => $user->id],
                ['viewed_at' => now()]
            );
        } catch (Throwable $e) {
            Log::warning('Story view record failed: ' . $e->getMessage());
        }

        return response()->json(['ok' => true]);
    }

    /**
     * DELETE /api/mobile/stories/{id}
     */
    public function destroy(int $id)
    {
        $user = Auth::guard('sanctum')->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $story = Story::find($id);
        if (!$story) {
            return response()->json(['message' => 'Story not found'], 404);
        }
        if ((int) $story->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Not allowed'], 403);
        }

        try {
            if ($story->media_path) {
                $this->deleteStoryMediaFile($story->media_path);
            }
            $story->delete();
        } catch (Throwable $e) {
            Log::error('Story destroy failed: ' . $e->getMessage());
            return response()->json(['message' => 'Could not delete story'], 500);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * GET /api/mobile/stories/{id}/viewers
     *
     * Returns the list of users who viewed this story, plus their reaction
     * (if any). Only the story owner can see this.
     */
    public function viewers(int $id)
    {
        $user = Auth::guard('sanctum')->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $story = Story::find($id);
        if (!$story) {
            return response()->json(['message' => 'Story not found'], 404);
        }
        if ((int) $story->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Not allowed'], 403);
        }

        $rows = DB::table('story_views as sv')
            ->join('users as u', 'u.id', '=', 'sv.user_id')
            ->leftJoin('story_reactions as sr', function ($j) use ($story) {
                $j->on('sr.user_id', '=', 'sv.user_id')->where('sr.story_id', $story->id);
            })
            ->where('sv.story_id', $story->id)
            ->orderByDesc('sv.viewed_at')
            ->limit(500)
            ->get(['u.id', 'u.name', 'u.image', 'sv.viewed_at', 'sr.emoji']);

        $captureAgg = DB::table('story_capture_events')
            ->where('story_id', $story->id)
            ->select(
                'viewer_id',
                DB::raw("SUM(CASE WHEN kind = 'screenshot' THEN 1 ELSE 0 END) as capture_screenshots"),
                DB::raw("SUM(CASE WHEN kind = 'screen_recording' THEN 1 ELSE 0 END) as capture_recordings")
            )
            ->groupBy('viewer_id')
            ->get()
            ->keyBy(fn ($row) => (int) $row->viewer_id);

        $viewers = $rows->map(function ($r) use ($captureAgg) {
            $avatar = null;
            if ($r->image) {
                $path = ltrim((string) $r->image, '/');
                $avatar = str_contains($path, 'img/profile/')
                    ? url('storage/' . $path)
                    : url('storage/img/profile/' . $path);
            }
            $vid = (int) $r->id;
            $cap = $captureAgg->get($vid);

            return [
                'id'                   => $vid,
                'name'                 => $r->name,
                'avatar'               => $avatar,
                'viewed_at'            => $r->viewed_at,
                'reaction'             => $r->emoji,
                'capture_screenshots'  => $cap ? (int) $cap->capture_screenshots : 0,
                'capture_recordings'   => $cap ? (int) $cap->capture_recordings : 0,
            ];
        });

        $ss = (int) DB::table('story_capture_events')
            ->where('story_id', $story->id)
            ->where('kind', 'screenshot')
            ->count();
        $sr = (int) DB::table('story_capture_events')
            ->where('story_id', $story->id)
            ->where('kind', 'screen_recording')
            ->count();

        return response()->json([
            'viewers'              => $viewers,
            'total'                => $viewers->count(),
            'reactions_count'      => $story->reactions()->count(),
            'capture_screenshots'  => $ss,
            'capture_recordings'   => $sr,
        ]);
    }

    /**
     * POST /api/mobile/stories/{id}/react
     * Body: { emoji: string }
     *
     * Adds or updates the auth user's reaction for the story. One reaction
     * per (user, story); re-reacting with a different emoji updates it.
     */
    public function react(int $id, Request $request)
    {
        $user = Auth::guard('sanctum')->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $validator = Validator::make($request->all(), [
            'emoji' => ['required', 'string', 'max:16'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid emoji', 'errors' => $validator->errors()], 422);
        }

        $story = $this->findVisibleActiveStory($id, $user);
        if (!$story) {
            return response()->json(['message' => 'Story not found or expired'], 404);
        }

        // Don't allow reacting to your own story (matches Instagram behaviour).
        if ((int) $story->user_id === (int) $user->id) {
            return response()->json(['message' => 'You cannot react to your own story'], 422);
        }

        $emoji = trim((string) $request->input('emoji'));

        try {
            StoryReaction::updateOrCreate(
                ['story_id' => $story->id, 'user_id' => $user->id],
                ['emoji' => $emoji]
            );
        } catch (Throwable $e) {
            Log::error('Story react failed: ' . $e->getMessage());
            return response()->json(['message' => 'Could not save reaction'], 500);
        }

        return response()->json([
            'ok'          => true,
            'my_reaction' => $emoji,
            'reactions_count' => $story->reactions()->count(),
        ]);
    }

    /**
     * DELETE /api/mobile/stories/{id}/react
     * Removes the auth user's reaction for the story (idempotent).
     */
    public function unreact(int $id)
    {
        $user = Auth::guard('sanctum')->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $story = $this->findVisibleActiveStory($id, $user);
        if (!$story) {
            return response()->json(['message' => 'Story not found or expired'], 404);
        }

        StoryReaction::where('story_id', $story->id)
            ->where('user_id', $user->id)
            ->delete();

        return response()->json([
            'ok' => true,
            'my_reaction' => null,
            'reactions_count' => $story->reactions()->count(),
        ]);
    }

    /**
     * POST /api/mobile/stories/{id}/reply
     * Body: { message: string }
     *
     * Sends a text reply to a story. The reply is delivered as a chat message
     * in the conversation between the auth user and the story's owner (via
     * the existing chat system, so it shows up in the user's inbox).
     */
    public function reply(int $id, Request $request)
    {
        $user = Auth::guard('sanctum')->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $validator = Validator::make($request->all(), [
            'message' => ['required', 'string', 'min:1', 'max:1000'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid reply', 'errors' => $validator->errors()], 422);
        }

        $story = $this->findVisibleActiveStory($id, $user);
        if (!$story) {
            return response()->json(['message' => 'Story not found or expired'], 404);
        }
        if ((int) $story->user_id === (int) $user->id) {
            return response()->json(['message' => 'You cannot reply to your own story'], 422);
        }

        $rawMessage = trim((string) $request->input('message'));

        // Embed the story context in the message body so the receiving client
        // can render it differently if it wants (or just show as plain text
        // for now).
        $body = json_encode([
            'type'         => 'story_reply',
            'story_id'     => (int) $story->id,
            'media_type'   => $story->media_type,
            'text'         => $rawMessage,
        ], JSON_UNESCAPED_UNICODE);

        try {
            // Open or create the conversation between the two users directly,
            // bypassing the follow-only restriction in ChatController. A user
            // who could view the story is allowed to reply to it.
            $a = min($user->id, $story->user_id);
            $b = max($user->id, $story->user_id);

            $conversation = Conversation::firstOrCreate(
                ['user_one_id' => $a, 'user_two_id' => $b]
            );

            $message = Message::create([
                'conversation_id' => $conversation->id,
                'sender_id'       => $user->id,
                'body'            => $body,
                'attachment_path' => null,
                'attachment_type' => null,
                'attachment_name' => null,
                'is_read'         => false,
            ]);

            // Best-effort: poke the real-time chat channel so the recipient's
            // open chat thread updates immediately if they're online. We try
            // Ably first (matches ChatController's transport); if the SDK or
            // key isn't configured, we silently skip — the message will still
            // appear next time the user opens the chat thread.
            try {
                $ablyKey = config('services.ably.key');
                if ($ablyKey && class_exists(\Ably\AblyRest::class)) {
                    $ably = new \Ably\AblyRest($ablyKey);
                    $channel = $ably->channels->get('chat:conversation:' . $conversation->id);
                    $channel->publish('message.new', [
                        'id'              => $message->id,
                        'conversation_id' => $conversation->id,
                        'sender_id'       => $user->id,
                        'body'            => $body,
                        'created_at'      => $message->created_at?->toIso8601String(),
                    ]);
                }
            } catch (Throwable $e) {
                Log::warning('Story reply Ably publish failed: ' . $e->getMessage());
            }

            return response()->json([
                'ok'              => true,
                'conversation_id' => $conversation->id,
                'message_id'      => $message->id,
            ]);
        } catch (Throwable $e) {
            Log::error('Story reply failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'message' => 'Could not send reply.',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * POST /api/mobile/stories/{id}/mention-repost
     *
     * Creates a copy of the story media on the authed user's account when they
     * were @mentioned on the original story. Mention overlays are stripped.
     */
    public function mentionRepost(int $id)
    {
        $user = Auth::guard('sanctum')->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $story = $this->findVisibleActiveStory($id, $user);
        if (!$story) {
            return response()->json(['message' => 'Story not found or expired'], 404);
        }
        if ((int) $story->user_id === (int) $user->id) {
            return response()->json(['message' => 'Use your own story composer for new posts'], 422);
        }

        $allowed = DB::table('story_mentions')
            ->where('story_id', $story->id)
            ->where('mentioned_user_id', $user->id)
            ->exists();
        if (!$allowed) {
            return response()->json(['message' => 'You are not mentioned on this story'], 403);
        }
        if (($story->audience ?: 'public') === 'close_friends') {
            return response()->json(['message' => 'Close friends stories cannot be reposted'], 403);
        }

        $src = (string) $story->media_path;
        if ($src === '' || ! $this->resolveStoryMediaDisk($src)) {
            return response()->json(['message' => 'Story media is missing'], 422);
        }

        $ext = pathinfo($src, PATHINFO_EXTENSION) ?: ($story->media_type === 'video' ? 'mp4' : 'jpg');
        $newFilename = 'story_' . $user->id . '_' . time() . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
        $newPath = self::STORIES_DIR . '/' . $newFilename;

        try {
            if (! $this->copyStoryMedia($src, $newPath, 'public')) {
                return response()->json(['message' => 'Could not copy media'], 500);
            }
        } catch (Throwable $e) {
            Log::error('mentionRepost copy failed: ' . $e->getMessage());

            return response()->json(['message' => 'Could not copy media'], 500);
        }

        $overlays = $this->overlaysForMentionRepost(is_array($story->overlays) ? $story->overlays : null);

        try {
            $newStory = Story::create([
                'user_id'     => $user->id,
                'media_path'  => $newPath,
                'media_type'  => $story->media_type,
                'audience'    => 'public',
                'overlays'    => $overlays,
                'duration_ms' => (int) ($story->duration_ms ?? 5000),
                'width'       => $story->width,
                'height'      => $story->height,
                'expires_at'  => now()->addHours(self::TTL_HOURS),
            ]);
        } catch (Throwable $e) {
            try {
                Storage::disk('public')->delete($newPath);
            } catch (Throwable $ignored) {
            }
            Log::error('mentionRepost create failed: ' . $e->getMessage());

            return response()->json(['message' => 'Could not create story'], 500);
        }

        $this->syncStoryMentionsFromOverlays($newStory, $overlays);

        $newStory->loadCount(['views as views_count', 'reactions as reactions_count']);
        $newStory->viewer_has_seen = 0;
        $newStory->viewer_reaction = null;

        return response()->json([
            'story' => $this->mapStory($newStory, $user->id, []),
        ], 201);
    }

    /**
     * POST /api/mobile/stories/{id}/capture-event
     * Body: { kind: "screenshot" | "screen_recording" }
     *
     * Best-effort logging when a viewer captures the screen (privacy signal for
     * the story owner). Self-captures are ignored.
     */
    public function reportCapture(int $id, Request $request)
    {
        $user = Auth::guard('sanctum')->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $kind = (string) $request->input('kind');
        if (!in_array($kind, ['screenshot', 'screen_recording'], true)) {
            return response()->json(['message' => 'Invalid kind'], 422);
        }

        $story = $this->findVisibleActiveStory($id, $user);
        if (!$story) {
            return response()->json(['message' => 'Story not found or expired'], 404);
        }
        if ((int) $story->user_id === (int) $user->id) {
            return response()->json(['ok' => true, 'ignored' => true]);
        }

        try {
            DB::table('story_capture_events')->insert([
                'story_id'  => $story->id,
                'viewer_id' => $user->id,
                'kind'      => $kind,
                'created_at'=> now(),
                'updated_at'=> now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('story_capture_events insert failed: ' . $e->getMessage());
        }

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/mobile/stories/{id}/report
     */
    public function report(int $id, Request $request)
    {
        $user = Auth::guard('sanctum')->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'reason' => 'required|string|min:10|max:2000',
        ]);

        $story = Story::query()->find($id);
        if (!$story || !$story->isVisibleTo($user)) {
            return response()->json(['message' => 'Story not found or expired'], 404);
        }
        if ((int) $story->user_id === (int) $user->id) {
            return response()->json(['message' => 'You cannot report your own story'], 422);
        }

        $report = StoryReport::query()->firstOrCreate(
            [
                'story_id' => (int) $story->id,
                'reporter_id' => (int) $user->id,
            ],
            [
                'reason' => (string) $validated['reason'],
                'status' => StoryReport::STATUS_PENDING,
            ]
        );
        if ($report->wasRecentlyCreated === false && $report->status === StoryReport::STATUS_PENDING) {
            $report->reason = (string) $validated['reason'];
            $report->save();
        }

        $this->notifyStaffAboutStoryReport($report, $user);

        return response()->json([
            'message' => 'Report submitted',
            'report' => [
                'id' => (int) $report->id,
                'story_id' => (int) $report->story_id,
                'status' => (string) $report->status,
            ],
        ], 201);
    }

    public function acceptReport(int $reportId)
    {
        return $this->resolveStoryReport($reportId, StoryReport::STATUS_ACCEPTED);
    }

    public function refuseReport(int $reportId)
    {
        return $this->resolveStoryReport($reportId, StoryReport::STATUS_REFUSED);
    }

    /**
     * GET /api/mobile/stories/archive — owner-only expired/hidden stories.
     */
    public function archive()
    {
        $user = Auth::guard('sanctum')->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $stories = Story::query()
            ->where('user_id', $user->id)
            ->withCount('views as views_count')
            ->withCount('reactions as reactions_count')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return response()->json([
            'stories' => $stories->map(fn (Story $s) => $this->mapStory($s, $user->id, []))->values(),
        ]);
    }

    /**
     * POST /api/mobile/stories/{id}/reshare — copy an owned archived story into a new 24h story.
     */
    public function reshare(int $id)
    {
        $user = Auth::guard('sanctum')->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $story = Story::query()->find($id);
        if (!$story || (int) $story->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Story not found'], 404);
        }
        if ($story->is_hidden) {
            return response()->json(['message' => 'This story was removed by moderation'], 422);
        }

        $src = (string) $story->media_path;
        if ($src === '' || ! $this->resolveStoryMediaDisk($src)) {
            return response()->json(['message' => 'Story media is missing'], 422);
        }

        $ext = pathinfo($src, PATHINFO_EXTENSION) ?: 'jpg';
        $newPath = self::STORIES_DIR . '/story_' . $user->id . '_' . time() . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
        $destDisk = $this->storyDiskForAudience($story->audience ?: 'public');
        if (! $this->copyStoryMedia($src, $newPath, $destDisk)) {
            return response()->json(['message' => 'Could not copy media'], 500);
        }

        $newStory = Story::create([
            'user_id'     => $user->id,
            'media_path'  => $newPath,
            'media_type'  => $story->media_type,
            'audience'    => $story->audience ?: 'public',
            'bg_color'    => $story->bg_color,
            'overlays'    => $story->overlays,
            'duration_ms' => (int) ($story->duration_ms ?? 5000),
            'width'       => $story->width,
            'height'      => $story->height,
            'expires_at'  => now()->addHours(self::TTL_HOURS),
            'is_hidden'   => false,
        ]);
        $this->syncStoryMentionsFromOverlays($newStory, is_array($newStory->overlays) ? $newStory->overlays : null);
        $this->persistInteractiveOverlays($newStory, is_array($newStory->overlays) ? $newStory->overlays : null);
        $this->persistHashtags($newStory, is_array($newStory->overlays) ? $newStory->overlays : null);
        $newStory->load(['interactions']);
        $newStory->loadCount(['views as views_count', 'reactions as reactions_count']);
        $newStory->viewer_has_seen = 0;
        $newStory->viewer_reaction = null;

        return response()->json(['story' => $this->mapStory($newStory, $user->id, [])], 201);
    }

    /**
     * POST /api/mobile/stories/{id}/share  { user_id }
     * Sends a story card through the existing chat conversation.
     */
    public function share(int $id, Request $request)
    {
        $user = Auth::guard('sanctum')->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
        ]);
        $recipientId = (int) $validated['user_id'];
        if ($recipientId === (int) $user->id) {
            return response()->json(['message' => 'Cannot share with yourself'], 422);
        }
        if (in_array($recipientId, $user->excludedAuthorIds(), true)) {
            return response()->json(['message' => 'Not allowed'], 403);
        }

        $story = $this->findVisibleActiveStory($id, $user);
        if (!$story) {
            return response()->json(['message' => 'Story not found or expired'], 404);
        }

        $recipient = User::query()->find($recipientId);
        if (!$recipient || !$story->isVisibleTo($recipient)) {
            return response()->json(['message' => 'This story cannot be shared with that user'], 403);
        }

        $a = min($user->id, $recipientId);
        $b = max($user->id, $recipientId);
        $conversation = Conversation::firstOrCreate(['user_one_id' => $a, 'user_two_id' => $b]);
        $body = json_encode([
            'type' => 'story_share',
            'story_id' => (int) $story->id,
            'media_type' => $story->media_type,
            'text' => 'Shared a story',
        ], JSON_UNESCAPED_UNICODE);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $user->id,
            'body' => $body,
            'is_read' => false,
        ]);

        return response()->json([
            'ok' => true,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
        ]);
    }

    /**
     * POST /api/mobile/stories/{id}/interact
     * { overlay_id, value }
     */
    public function interact(int $id, Request $request)
    {
        $user = Auth::guard('sanctum')->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $story = $this->findVisibleActiveStory($id, $user);
        if (!$story) {
            return response()->json(['message' => 'Story not found or expired'], 404);
        }
        if ((int) $story->user_id === (int) $user->id) {
            return response()->json(['message' => 'You cannot respond to your own sticker'], 422);
        }

        $overlayId = (string) $request->input('overlay_id', '');
        $interaction = StoryInteraction::query()
            ->where('story_id', $story->id)
            ->where('overlay_id', $overlayId)
            ->first();
        if (!$interaction) {
            return response()->json(['message' => 'Sticker not found'], 404);
        }

        $value = $this->normalizeInteractionValue($interaction, $request->input('value'));
        if ($value === null) {
            return response()->json(['message' => 'Invalid response'], 422);
        }

        $row = StoryInteractionResponse::query()->firstOrCreate(
            [
                'story_interaction_id' => $interaction->id,
                'user_id' => $user->id,
            ],
            ['payload' => $value]
        );
        if (!$row->wasRecentlyCreated && in_array($interaction->type, ['poll', 'quiz'], true)) {
            return response()->json(['message' => 'Already responded', 'duplicate' => true], 409);
        }
        if (!$row->wasRecentlyCreated) {
            $row->payload = $value;
            $row->save();
        }

        return response()->json([
            'ok' => true,
            'interaction' => $this->mapOneInteraction($interaction->fresh('responses'), $user->id, (int) $story->user_id),
        ]);
    }

    /**
     * GET /api/mobile/stories/{id}/interactions — owner analytics.
     */
    public function interactionResults(int $id)
    {
        $user = Auth::guard('sanctum')->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $story = Story::query()->with('interactions.responses.user:id,name,image')->find($id);
        if (!$story) {
            return response()->json(['message' => 'Story not found'], 404);
        }
        if ((int) $story->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Not allowed'], 403);
        }

        return response()->json([
            'interactions' => $story->interactions->map(fn ($i) => $this->mapOneInteraction($i, $user->id, $user->id, true))->values(),
        ]);
    }

    private function resolveStoryReport(int $reportId, string $status)
    {
        $user = Auth::guard('sanctum')->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }
        if (!$this->isStaff($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $report = StoryReport::query()->with('story')->findOrFail($reportId);
        if ($report->status !== StoryReport::STATUS_PENDING) {
            return response()->json(['message' => 'This report was already resolved'], 409);
        }

        DB::transaction(function () use ($report, $status, $user) {
            $report->status = $status;
            $report->reviewed_by = (int) $user->id;
            $report->reviewed_at = now();
            $report->save();
            if ($report->story) {
                $report->story->is_hidden = $status === StoryReport::STATUS_ACCEPTED;
                $report->story->save();
            }
            StoryReportNotification::query()
                ->where('story_report_id', $report->id)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
        });

        return response()->json([
            'message' => 'Report updated',
            'report' => [
                'id' => (int) $report->id,
                'status' => (string) $report->status,
                'story_is_hidden' => (bool) ($report->story?->is_hidden ?? false),
            ],
        ]);
    }

    private function notifyStaffAboutStoryReport(StoryReport $report, User $reporter): void
    {
        $staffRoles = ['admin', 'super_admin', 'moderateur', 'coach', 'studio_responsable'];
        $admins = User::query()
            ->select(['id', 'name', 'image', 'role'])
            ->where(function ($q) use ($staffRoles) {
                foreach ($staffRoles as $role) {
                    $q->orWhereJsonContains('role', $role);
                }
            })
            ->get();
        if ($admins->isEmpty()) {
            return;
        }
        foreach ($admins as $admin) {
            try {
                StoryReportNotification::query()->create([
                    'notified_user_id' => (int) $admin->id,
                    'story_report_id' => (int) $report->id,
                ]);
            } catch (Throwable $e) {
                Log::warning('story report notify failed: '.$e->getMessage());
            }
        }
    }

    private function isStaff(User $user): bool
    {
        $roles = is_array($user->role) ? $user->role : [$user->role];
        $allowed = ['admin', 'super_admin', 'moderateur', 'coach', 'studio_responsable'];

        return count(array_intersect($roles, $allowed)) > 0;
    }

    private function sanitizeHexColor($raw): ?string
    {
        $v = is_string($raw) ? trim($raw) : '';
        if ($v === '') {
            return null;
        }
        if ($v[0] !== '#') {
            $v = '#'.$v;
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $v)) {
            return null;
        }

        return strtolower($v);
    }

    private function writeSolidPng(int $userId, string $hex, string $disk = 'public'): string
    {
        $hex = $this->sanitizeHexColor($hex) ?: '#111111';
        $filename = 'story_'.$userId.'_'.time().'_'.substr(bin2hex(random_bytes(4)), 0, 8).'.png';
        $path = self::STORIES_DIR.'/'.$filename;
        if (function_exists('imagecreatetruecolor')) {
            $im = imagecreatetruecolor(1080, 1920);
            $r = hexdec(substr($hex, 1, 2));
            $g = hexdec(substr($hex, 3, 2));
            $b = hexdec(substr($hex, 5, 2));
            imagefill($im, 0, 0, imagecolorallocate($im, $r, $g, $b));
            ob_start();
            imagepng($im);
            $data = ob_get_clean();
            imagedestroy($im);
            Storage::disk($disk)->put($path, $data);

            return $path;
        }
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        Storage::disk($disk)->put($path, $png);

        return $path;
    }

    /**
     * Stream a private story asset (audio/sticker/etc.) via signed URL.
     * Re-checks story visibility and that the path belongs to the story.
     */
    public function streamAsset(Request $request)
    {
        $path = (string) $request->query('path', '');
        $relative = ltrim($path, '/');
        if ($relative === '' || ! str_starts_with($relative, self::STORIES_DIR.'/')) {
            abort(404);
        }
        if (str_contains($relative, '..')) {
            abort(404);
        }

        $storyId = (int) $request->query('story', 0);
        $story = $storyId > 0 ? Story::query()->find($storyId) : null;
        if (! $story) {
            abort(404);
        }

        $this->authorizeSignedStoryStream($story, $request);

        if (! $this->storyOwnsAssetPath($story, $relative)) {
            abort(404);
        }

        if (! Storage::disk(self::STORIES_PRIVATE_DISK)->exists($relative)) {
            abort(404);
        }

        return Storage::disk(self::STORIES_PRIVATE_DISK)->response($relative);
    }

    /**
     * Optional user-owned audio attached to a story. Returns a URL or null.
     */
    private function storeUserStoryAudio(Request $request, int $userId, string $disk = 'public'): ?string
    {
        $file = $request->file('audio');
        if (!$file) {
            return null;
        }

        $maxBytes = 8 * 1024 * 1024;
        if ($file->getSize() > $maxBytes) {
            throw new \RuntimeException('Audio file is too large. Max 8 MB.');
        }

        $mime = strtolower((string) $file->getMimeType());
        $ok = ['audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/aac', 'audio/x-m4a', 'audio/m4a', 'audio/wav', 'audio/x-wav', 'audio/x-hx-aac-adts'];
        if (!in_array($mime, $ok, true)) {
            throw new \RuntimeException('Unsupported audio type.');
        }

        $ext = 'mp3';
        if (str_contains($mime, 'mp4') || str_contains($mime, 'm4a') || str_contains($mime, 'aac')) {
            $ext = 'm4a';
        } elseif (str_contains($mime, 'wav')) {
            $ext = 'wav';
        }

        $filename = 'audio_'.$userId.'_'.time().'_'.substr(bin2hex(random_bytes(4)), 0, 8).'.'.$ext;
        $path = $file->storeAs(self::STORIES_DIR, $filename, $disk);

        return $this->storyAssetUrl($path, $disk);
    }

    private function storeNamedImageUploads(Request $request, int $userId, string $prefix, string $disk = 'public'): array
    {
        $out = [];
        foreach ($request->allFiles() as $key => $file) {
            if (!is_string($key) || !str_starts_with($key, $prefix) || !($file instanceof \Illuminate\Http\UploadedFile)) {
                continue;
            }
            $id = substr($key, strlen($prefix));
            $url = $this->storePublicImageFile($file, $userId, 'sticker', $disk);
            if ($url) {
                $out[$id] = $url;
            }
        }

        return $out;
    }

    private function storeBoomerangUploads(Request $request, int $userId, string $disk = 'public'): array
    {
        $files = $request->file('boomerang', []);
        if (!is_array($files)) {
            $files = $files ? [$files] : [];
        }
        $urls = [];
        foreach (array_slice($files, 0, 12) as $file) {
            if (!($file instanceof \Illuminate\Http\UploadedFile)) {
                continue;
            }
            $url = $this->storePublicImageFile($file, $userId, 'boom', $disk);
            if ($url) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    private function storePublicImageFile(\Illuminate\Http\UploadedFile $file, int $userId, string $kind, string $disk = 'public'): ?string
    {
        if ($file->getSize() > self::MAX_PHOTO_BYTES) {
            return null;
        }
        $mime = strtolower((string) $file->getMimeType());
        $ok = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic', 'image/heif'];
        if (!in_array($mime, $ok, true)) {
            return null;
        }
        $ext = $this->extensionForMime($mime, 'image');
        if ($mime === 'image/gif') {
            $ext = 'gif';
        }
        $filename = $kind.'_'.$userId.'_'.time().'_'.substr(bin2hex(random_bytes(4)), 0, 8).'.'.$ext;
        $path = $file->storeAs(self::STORIES_DIR, $filename, $disk);

        return $this->storyAssetUrl($path, $disk);
    }

    /**
     * Allowlisted HTTPS hosts for catalog music previews / covers.
     * Arbitrary client URLs are rejected so overlays cannot point at random audio.
     */
    private function sanitizeCatalogMediaUrl(mixed $url, string $kind): ?string
    {
        if (!is_string($url) || $url === '') {
            return null;
        }

        $url = trim($url);
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'https') {
            return null;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $previewHosts = [
            'p.scdn.co',
            'audio-ak-spotify-com.akamaized.net',
            'audio-fa.scdn.co',
            'audio-ssl.itunes.apple.com',
            'audio.itunes.apple.com',
        ];
        $previewSuffixes = ['.scdn.co', '.mzstatic.com'];
        $coverSuffixes = ['.scdn.co', '.spotifycdn.com', '.mzstatic.com'];

        if ($kind === 'cover') {
            foreach ($coverSuffixes as $suffix) {
                if ($host === ltrim($suffix, '.') || str_ends_with($host, $suffix)) {
                    return $url;
                }
            }

            return null;
        }

        if (in_array($host, $previewHosts, true)) {
            return $url;
        }
        foreach ($previewSuffixes as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return $url;
            }
        }

        return null;
    }

    private function sanitizeOverlaysPayload(
        $overlaysRaw,
        ?string $audioUrl,
        array $stickerUrls,
        array $layoutCellUrls,
        array $boomerangUrls
    ): ?array {
        if (!is_string($overlaysRaw) || $overlaysRaw === '') {
            return null;
        }
        $decoded = json_decode($overlaysRaw, true);
        if (!is_array($decoded)) {
            return null;
        }
        $overlays = array_values(array_filter(array_map(function ($o) use ($audioUrl, $stickerUrls, $layoutCellUrls, $boomerangUrls) {
            if (!is_array($o) || empty($o['type'])) {
                return null;
            }
            $base = [
                'id'       => (string) ($o['id'] ?? bin2hex(random_bytes(6))),
                'type'     => (string) $o['type'],
                'x'        => (float) ($o['x'] ?? 0.5),
                'y'        => (float) ($o['y'] ?? 0.5),
                'scale'    => (float) ($o['scale'] ?? 1.0),
                'rotation' => (float) ($o['rotation'] ?? 0.0),
            ];
            if ($base['type'] === 'text') {
                $base['text']      = mb_substr((string) ($o['text'] ?? ''), 0, 300);
                $base['color']     = is_string($o['color'] ?? null) ? substr($o['color'], 0, 16) : '#ffffff';
                $base['font']      = is_string($o['font'] ?? null) ? substr($o['font'], 0, 32) : 'default';
                $align = strtolower((string) ($o['align'] ?? 'center'));
                $base['align']     = in_array($align, ['left', 'center', 'right'], true) ? $align : 'center';
                $anim = strtolower((string) ($o['anim'] ?? 'none'));
                $base['anim']      = in_array($anim, ['none', 'pulse', 'fade'], true) ? $anim : 'none';
                $base['has_bg']    = !empty($o['has_bg']);
                $base['bg_color']  = is_string($o['bg_color'] ?? null) ? substr($o['bg_color'], 0, 16) : null;
                $base = array_merge($base, $this->overlayMeasuredSize($o));
                if ($base['text'] === '') {
                    return null;
                }

                return $base;
            }
            if ($base['type'] === 'sticker') {
                $base['emoji'] = mb_substr((string) ($o['emoji'] ?? ''), 0, 8);
                $uploaded = $stickerUrls[$base['id']] ?? null;
                $imageUrl = is_string($uploaded) ? $uploaded : null;
                if ($imageUrl) {
                    $base['image_url'] = $imageUrl;
                }
                if (!empty($o['cutout'])) {
                    $base['cutout'] = true;
                }
                if (($base['emoji'] ?? '') === '' && empty($base['image_url'])) {
                    return null;
                }
                $base = array_merge($base, $this->overlayMeasuredSize($o));

                return $base;
            }
            if ($base['type'] === 'hashtag') {
                $tag = strtolower(preg_replace('/[^\w]/', '', (string) ($o['tag'] ?? $o['text'] ?? '')));
                if (strlen($tag) < 2) {
                    return null;
                }
                $base['tag'] = mb_substr($tag, 0, 32);

                return $base;
            }
            if ($base['type'] === 'location') {
                $label = mb_substr(trim((string) ($o['label'] ?? $o['text'] ?? '')), 0, 80);
                if ($label === '') {
                    return null;
                }
                $base['label'] = $label;

                return $base;
            }
            if ($base['type'] === 'layout') {
                $template = (string) ($o['template'] ?? '2v');
                if (!in_array($template, ['2v', '2h', '3', '4'], true)) {
                    $template = '2v';
                }
                $cells = [];
                $rawCells = is_array($o['cells'] ?? null) ? $o['cells'] : [];
                foreach (array_slice($rawCells, 0, 4) as $i => $_cell) {
                    $url = $layoutCellUrls[(string) $i] ?? $layoutCellUrls[$i] ?? null;
                    if ($url) {
                        $cells[] = ['image_url' => $url];
                    }
                }
                if (count($cells) < 2) {
                    return null;
                }
                $base['template'] = $template;
                $base['cells'] = $cells;
                $base['x'] = 0.5;
                $base['y'] = 0.5;

                return $base;
            }
            if ($base['type'] === 'boomerang') {
                $frames = $boomerangUrls;
                if ($frames === []) {
                    return null;
                }
                $base['frames'] = $frames;
                $base['x'] = 0.5;
                $base['y'] = 0.5;

                return $base;
            }
            if ($base['type'] === 'media_transform') {
                $base['x'] = max(0, min(1, (float) ($o['x'] ?? 0.5)));
                $base['y'] = max(0, min(1, (float) ($o['y'] ?? 0.5)));
                $base['scale'] = max(0.5, min(4, (float) ($o['scale'] ?? 1)));
                $base['rotation'] = (float) ($o['rotation'] ?? 0);

                return $base;
            }
            if ($base['type'] === 'blur') {
                $base['intensity'] = max(4, min(40, (float) ($o['intensity'] ?? 12)));

                return $base;
            }
            if ($base['type'] === 'gradient') {
                $colors = [];
                foreach ((array) ($o['colors'] ?? []) as $c) {
                    $hex = is_string($c) ? strtolower(substr($c, 0, 16)) : '';
                    if (preg_match('/^#[0-9a-f]{6}$/', $hex)) {
                        $colors[] = $hex;
                    }
                    if (count($colors) >= 3) {
                        break;
                    }
                }
                if (count($colors) < 2) {
                    return null;
                }
                $base['colors'] = $colors;

                return $base;
            }
            if ($base['type'] === 'mention') {
                $mentioned = User::query()->find((int) ($o['user_id'] ?? 0));
                if (!$mentioned) {
                    return null;
                }
                $base['user_id']  = (int) $mentioned->id;
                $base['username'] = mb_substr((string) $mentioned->name, 0, 80);
                $base['color']    = is_string($o['color'] ?? null) ? substr($o['color'], 0, 16) : '#ffffff';
                $base['has_bg']   = !empty($o['has_bg']);
                $base['bg_color'] = is_string($o['bg_color'] ?? null) ? substr($o['bg_color'], 0, 16) : null;
                $base['hidden']   = !empty($o['hidden']);
                $base = array_merge($base, $this->overlayMeasuredSize($o));

                return $base;
            }
            if ($base['type'] === 'drawing') {
                $rawPoints = $o['points'] ?? [];
                if (!is_array($rawPoints)) {
                    return null;
                }
                $points = [];
                foreach ($rawPoints as $p) {
                    if (!is_array($p) || count($p) < 2) {
                        continue;
                    }
                    $px = (float) $p[0];
                    $py = (float) $p[1];
                    if ($px < 0) {
                        $px = 0;
                    }
                    if ($px > 1) {
                        $px = 1;
                    }
                    if ($py < 0) {
                        $py = 0;
                    }
                    if ($py > 1) {
                        $py = 1;
                    }
                    $points[] = [$px, $py];
                    if (count($points) >= 200) {
                        break;
                    }
                }
                if (count($points) < 2) {
                    return null;
                }
                $brush = strtolower((string) ($o['brush'] ?? 'pen'));
                $base['points']       = $points;
                $base['color']        = is_string($o['color'] ?? null) ? substr($o['color'], 0, 16) : '#ffffff';
                $base['stroke_width'] = max(1.0, min(40.0, (float) ($o['stroke_width'] ?? 6.0)));
                $base['brush']        = in_array($brush, ['pen', 'highlighter', 'neon', 'eraser'], true) ? $brush : 'pen';
                $base['opacity']      = max(0.1, min(1.0, (float) ($o['opacity'] ?? 1)));

                return $base;
            }
            if ($base['type'] === 'filter') {
                $hex = is_string($o['color'] ?? null) ? strtolower(substr($o['color'], 0, 16)) : '';
                if (!preg_match('/^#[0-9a-f]{6}$/', $hex)) {
                    return null;
                }
                $base['name'] = mb_substr((string) ($o['name'] ?? 'filter'), 0, 24);
                $base['color'] = $hex;
                $base['opacity'] = max(0.05, min(0.6, (float) ($o['opacity'] ?? 0.2)));
                $base['x'] = 0.5;
                $base['y'] = 0.5;

                return $base;
            }
            if ($base['type'] === 'music') {
                $catalogPreview = $this->sanitizeCatalogMediaUrl(
                    $o['preview_url'] ?? $o['url'] ?? $o['local_uri'] ?? null,
                    'preview'
                );
                $resolvedAudio = $audioUrl ?: $catalogPreview;
                if (!$resolvedAudio) {
                    return null;
                }
                $source = strtolower((string) ($o['source'] ?? ($audioUrl ? 'user' : 'spotify')));
                if ($audioUrl) {
                    $source = 'user';
                } elseif (!in_array($source, ['spotify', 'itunes', 'spotify+itunes'], true)) {
                    $source = 'spotify';
                }
                $base['source'] = $source;
                $base['preview_url'] = $resolvedAudio;
                $base['title'] = mb_substr((string) ($o['title'] ?? ($audioUrl ? 'Your audio' : 'Track')), 0, 80);
                $base['artist'] = mb_substr((string) ($o['artist'] ?? ''), 0, 80);
                $base['album'] = mb_substr((string) ($o['album'] ?? ''), 0, 80);
                $base['track_id'] = mb_substr((string) ($o['track_id'] ?? ''), 0, 80);
                $cover = $this->sanitizeCatalogMediaUrl($o['cover_url'] ?? null, 'cover');
                if ($cover) {
                    $base['cover_url'] = $cover;
                }
                $base['display'] = (($o['display'] ?? 'sticker') === 'none') ? 'none' : 'sticker';
                $base['start_ms'] = max(0, (int) ($o['start_ms'] ?? 0));
                $base['end_ms'] = max($base['start_ms'] + 1000, min(60000, (int) ($o['end_ms'] ?? 60000)));
                $base['original_volume'] = max(0, min(1, (float) ($o['original_volume'] ?? 0)));
                $base['music_volume'] = max(0, min(1, (float) ($o['music_volume'] ?? 0.85)));

                return $base;
            }
            $interactive = $this->sanitizeInteractiveOverlay($base, $o);
            if ($interactive) {
                return $interactive;
            }

            return null;
        }, $decoded)));
        if (count($overlays) > 32) {
            $overlays = array_slice($overlays, 0, 32);
        }

        return $overlays === [] ? null : $overlays;
    }

    private function notifyStoryMentions(Story $story, User $sender, ?array $overlays): void
    {
        if (!is_array($overlays)) {
            return;
        }
        foreach ($overlays as $o) {
            if (!is_array($o) || ($o['type'] ?? '') !== 'mention') {
                continue;
            }
            $uid = (int) ($o['user_id'] ?? 0);
            if ($uid <= 0 || $uid === (int) $sender->id) {
                continue;
            }
            $recipient = User::query()->find($uid);
            if (!$recipient) {
                continue;
            }
            try {
                $notification = StoryNotification::query()->firstOrCreate(
                    [
                        'user_id' => $uid,
                        'sender_id' => (int) $sender->id,
                        'story_id' => (int) $story->id,
                        'type' => StoryNotification::TYPE_MENTION,
                    ]
                );
                if ($notification->wasRecentlyCreated && $recipient->expo_push_token) {
                    app(ExpoPushNotificationService::class)->sendToUser(
                        $recipient,
                        'Story mention',
                        ($sender->name ?: 'Someone').' mentioned you in a story',
                        [
                            'type' => 'story_mention',
                            'story_id' => (int) $story->id,
                            'sender_id' => (int) $sender->id,
                        ]
                    );
                }
            } catch (Throwable $e) {
                Log::warning('story mention notify failed: '.$e->getMessage());
            }
        }
    }

    private function sanitizeInteractiveOverlay(array $base, array $o): ?array
    {
        $type = $base['type'];
        if ($type === 'poll') {
            $question = mb_substr(trim((string) ($o['question'] ?? '')), 0, 120);
            $options = [];
            foreach ((array) ($o['options'] ?? []) as $opt) {
                $label = mb_substr(trim((string) $opt), 0, 40);
                if ($label !== '') {
                    $options[] = $label;
                }
                if (count($options) >= 4) {
                    break;
                }
            }
            if ($question === '' || count($options) < 2) {
                return null;
            }
            $base['question'] = $question;
            $base['options'] = $options;

            return $base;
        }
        if ($type === 'question') {
            $prompt = mb_substr(trim((string) ($o['prompt'] ?? $o['question'] ?? '')), 0, 120);
            if ($prompt === '') {
                return null;
            }
            $base['prompt'] = $prompt;

            return $base;
        }
        if ($type === 'quiz') {
            $question = mb_substr(trim((string) ($o['question'] ?? '')), 0, 120);
            $options = [];
            foreach ((array) ($o['options'] ?? []) as $opt) {
                $label = mb_substr(trim((string) $opt), 0, 40);
                if ($label !== '') {
                    $options[] = $label;
                }
                if (count($options) >= 4) {
                    break;
                }
            }
            $correct = (int) ($o['correct_index'] ?? 0);
            if ($question === '' || count($options) < 2) {
                return null;
            }
            $base['question'] = $question;
            $base['options'] = $options;
            $base['correct_index'] = max(0, min(count($options) - 1, $correct));

            return $base;
        }
        if ($type === 'slider') {
            $prompt = mb_substr(trim((string) ($o['prompt'] ?? $o['question'] ?? '')), 0, 120);
            $emoji = mb_substr((string) ($o['emoji'] ?? '🔥'), 0, 8);
            if ($prompt === '') {
                return null;
            }
            $base['prompt'] = $prompt;
            $base['emoji'] = $emoji !== '' ? $emoji : '🔥';

            return $base;
        }
        if ($type === 'countdown') {
            $title = mb_substr(trim((string) ($o['title'] ?? '')), 0, 80);
            $endsAt = (string) ($o['ends_at'] ?? '');
            $tz = mb_substr((string) ($o['timezone'] ?? config('app.timezone', 'Africa/Casablanca')), 0, 64);
            try {
                $dt = new \DateTimeImmutable($endsAt);
            } catch (Throwable $e) {
                return null;
            }
            if ($title === '') {
                return null;
            }
            $base['title'] = $title;
            $base['ends_at'] = $dt->format(DATE_ATOM);
            $base['timezone'] = $tz !== '' ? $tz : (string) config('app.timezone', 'Africa/Casablanca');

            return $base;
        }
        if ($type === 'link') {
            $url = trim((string) ($o['url'] ?? ''));
            if (!preg_match('#^https://#i', $url)) {
                return null;
            }
            $host = parse_url($url, PHP_URL_HOST);
            if (!$host || strcasecmp((string) $host, 'localhost') === 0) {
                return null;
            }
            $base['url'] = mb_substr($url, 0, 500);
            $base['label'] = mb_substr(trim((string) ($o['label'] ?? $host)), 0, 40);

            return $base;
        }

        return null;
    }

    private function persistInteractiveOverlays(Story $story, ?array $overlays): void
    {
        if (!is_array($overlays)) {
            return;
        }
        foreach ($overlays as $o) {
            if (!is_array($o) || empty($o['id']) || empty($o['type'])) {
                continue;
            }
            if (!in_array($o['type'], ['poll', 'question', 'quiz', 'slider', 'countdown', 'link'], true)) {
                continue;
            }
            $payload = $o;
            unset($payload['x'], $payload['y'], $payload['scale'], $payload['rotation']);
            StoryInteraction::query()->updateOrCreate(
                ['story_id' => $story->id, 'overlay_id' => (string) $o['id']],
                ['type' => $o['type'], 'payload' => $payload]
            );
        }
    }

    private function persistHashtags(Story $story, ?array $overlays): void
    {
        if (!is_array($overlays)) {
            return;
        }
        $tags = [];
        foreach ($overlays as $o) {
            if (($o['type'] ?? '') === 'hashtag') {
                $tag = strtolower(preg_replace('/[^\w]/', '', (string) ($o['tag'] ?? '')));
                if (strlen($tag) >= 2 && strlen($tag) <= 32) {
                    $tags[$tag] = true;
                }
            }
            $text = (string) ($o['text'] ?? $o['question'] ?? $o['prompt'] ?? $o['label'] ?? '');
            if ($text === '') {
                continue;
            }
            if (preg_match_all('/#([A-Za-z0-9_]{2,32})/', $text, $m)) {
                foreach ($m[1] as $tag) {
                    $tags[strtolower($tag)] = true;
                }
            }
        }
        foreach (array_keys($tags) as $tag) {
            DB::table('story_hashtags')->insertOrIgnore([
                'story_id' => $story->id,
                'tag' => $tag,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function mapInteractionsForViewer(Story $s, int $authUserId): array
    {
        $s->loadMissing('interactions.responses');

        return $s->interactions->map(fn ($i) => $this->mapOneInteraction($i, $authUserId, (int) $s->user_id))->values()->all();
    }

    private function mapOneInteraction(StoryInteraction $i, int $authUserId, int $ownerId, bool $ownerAnalytics = false): array
    {
        $isOwner = $authUserId === $ownerId;
        $payload = is_array($i->payload) ? $i->payload : [];
        $correctIndex = array_key_exists('correct_index', $payload) ? $payload['correct_index'] : null;
        if ($i->type === 'quiz' && !$isOwner) {
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
            'responses_count' => $i->responses ? $i->responses->count() : (int) $i->responses()->count(),
            'my_response' => $my,
        ];
        if ($i->type === 'quiz' && is_array($my) && $correctIndex !== null) {
            $out['is_correct'] = ((int) ($my['index'] ?? -1)) === (int) $correctIndex;
        }
        if ($isOwner || $ownerAnalytics) {
            if ($i->type === 'question') {
                $out['responses'] = ($i->responses ?? collect())->map(function ($r) {
                    return [
                        'user_id' => (int) $r->user_id,
                        'name' => $r->user->name ?? null,
                        'text' => is_array($r->payload) ? ($r->payload['text'] ?? null) : null,
                    ];
                })->values();
            }
            if (in_array($i->type, ['poll', 'quiz'], true)) {
                $counts = [];
                foreach ($i->responses ?? [] as $r) {
                    $idx = (int) (is_array($r->payload) ? ($r->payload['index'] ?? -1) : -1);
                    $counts[$idx] = ($counts[$idx] ?? 0) + 1;
                }
                $out['counts'] = $counts;
            }
            if ($i->type === 'slider') {
                $vals = [];
                foreach ($i->responses ?? [] as $r) {
                    $vals[] = (int) (is_array($r->payload) ? ($r->payload['value'] ?? 0) : 0);
                }
                $out['average'] = $vals === [] ? null : array_sum($vals) / count($vals);
            }
        }

        return $out;
    }

    private function normalizeInteractionValue(StoryInteraction $interaction, $raw): ?array
    {
        if ($interaction->type === 'poll' || $interaction->type === 'quiz') {
            $index = is_array($raw) ? (int) ($raw['index'] ?? $raw) : (int) $raw;
            $options = $interaction->payload['options'] ?? [];
            if ($index < 0 || $index >= count($options)) {
                return null;
            }

            return ['index' => $index];
        }
        if ($interaction->type === 'question') {
            $text = mb_substr(trim((string) (is_array($raw) ? ($raw['text'] ?? '') : $raw)), 0, 300);
            if ($text === '') {
                return null;
            }

            return ['text' => $text];
        }
        if ($interaction->type === 'slider') {
            $value = is_array($raw) ? (int) ($raw['value'] ?? 0) : (int) $raw;
            $value = max(0, min(100, $value));

            return ['value' => $value];
        }
        if ($interaction->type === 'link') {
            return ['opened' => true];
        }
        if ($interaction->type === 'countdown') {
            return ['remind' => true];
        }

        return null;
    }
}
