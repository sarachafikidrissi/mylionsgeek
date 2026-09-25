<?php

namespace App\Http\Controllers\API\Concerns;

use App\Models\Story;
use App\Models\User;
use Illuminate\Support\Facades\URL;

trait ServesStoryMedia
{
    private const STORIES_DIR = 'stories';
    private const STORIES_PRIVATE_DISK = 'stories';
    private const CLOSE_FRIENDS_URL_TTL_MINUTES = 15;

    private function publicUrl(string $path): string
    {
        return url('storage/' . ltrim($path, '/'));
    }

    /**
     * Close-friends media is never a stable public /storage URL.
     * Clients receive a short-lived signed URL bound to the viewer; stream
     * endpoints re-check visibility (hide / close-friends / expiry).
     */
    private function mediaUrl(Story $s, ?int $viewerId = null): string
    {
        $path = ltrim((string) $s->media_path, '/');
        if ($path === '') {
            return '';
        }

        if (($s->audience ?: 'public') === 'close_friends') {
            $params = ['story' => $s->id];
            if ($viewerId && $viewerId > 0) {
                $params['viewer'] = $viewerId;
            }

            return URL::temporarySignedRoute(
                'mobile.stories.file',
                now()->addMinutes(self::CLOSE_FRIENDS_URL_TTL_MINUTES),
                $params
            );
        }

        return $this->publicUrl($path);
    }

    private function overlaysForViewer(Story $s, int $authUserId): array
    {
        $overlays = is_array($s->overlays) ? $s->overlays : [];
        $isOwner = (int) $s->user_id === $authUserId;
        $isCloseFriends = ($s->audience ?: 'public') === 'close_friends';

        return array_values(array_map(function ($o) use ($isOwner, $isCloseFriends, $s, $authUserId) {
            if (! is_array($o)) {
                return $o;
            }
            if (($o['type'] ?? '') === 'quiz' && ! $isOwner) {
                unset($o['correct_index']);
            }
            if ($isCloseFriends) {
                $o = $this->freshPrivateOverlayUrls($o, $s, $authUserId);
            }

            return $o;
        }, $overlays));
    }

    /**
     * Rewrite stored private-disk asset refs into short-lived signed URLs for this viewer.
     */
    private function freshPrivateOverlayUrls(array $overlay, Story $story, int $viewerId): array
    {
        foreach (['preview_url', 'image_url', 'url'] as $key) {
            if (! empty($overlay[$key]) && is_string($overlay[$key])) {
                $overlay[$key] = $this->freshPrivateAssetUrl($overlay[$key], $story, $viewerId);
            }
        }
        if (! empty($overlay['frames']) && is_array($overlay['frames'])) {
            $overlay['frames'] = array_map(
                fn ($frame) => is_string($frame)
                    ? $this->freshPrivateAssetUrl($frame, $story, $viewerId)
                    : $frame,
                $overlay['frames']
            );
        }
        if (! empty($overlay['cells']) && is_array($overlay['cells'])) {
            $overlay['cells'] = array_map(function ($cell) use ($story, $viewerId) {
                if (is_array($cell) && ! empty($cell['image_url']) && is_string($cell['image_url'])) {
                    $cell['image_url'] = $this->freshPrivateAssetUrl($cell['image_url'], $story, $viewerId);
                }

                return $cell;
            }, $overlay['cells']);
        }

        return $overlay;
    }

    private function freshPrivateAssetUrl(string $stored, Story $story, int $viewerId): string
    {
        $path = $this->extractPrivateAssetPath($stored);
        if ($path === null) {
            return $stored;
        }

        return $this->storyAssetUrl($path, self::STORIES_PRIVATE_DISK, (int) $story->id, $viewerId);
    }

    /**
     * Relative private-disk path only. Does not treat /storage/stories/ URLs
     * as owned paths (those are public-disk or client-supplied).
     */
    private function extractPrivateAssetPath(string $stored): ?string
    {
        $stored = trim($stored);
        if ($stored === '') {
            return null;
        }

        if (str_starts_with($stored, self::STORIES_DIR.'/') && ! str_contains($stored, '://')) {
            $relative = ltrim($stored, '/');
            if (str_contains($relative, '..')) {
                return null;
            }

            return $relative;
        }

        $query = parse_url($stored, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            parse_str($query, $params);
            $path = isset($params['path']) ? ltrim((string) $params['path'], '/') : '';
            if ($path !== '' && str_starts_with($path, self::STORIES_DIR.'/') && ! str_contains($path, '..')) {
                return $path;
            }
        }

        return null;
    }

    /**
     * URL for a story-owned asset.
     * Private-disk: persist relative path at upload; issue signed URLs only when
     * serving to a known viewer (refreshed via authenticated story APIs).
     */
    private function storyAssetUrl(string $path, string $disk, ?int $storyId = null, ?int $viewerId = null): string
    {
        $relative = ltrim($path, '/');
        if ($disk === self::STORIES_PRIVATE_DISK) {
            if (! $storyId || ! $viewerId) {
                return $relative;
            }

            return URL::temporarySignedRoute(
                'mobile.stories.asset',
                now()->addMinutes(self::CLOSE_FRIENDS_URL_TTL_MINUTES),
                [
                    'path' => $relative,
                    'story' => $storyId,
                    'viewer' => $viewerId,
                ]
            );
        }

        return $this->publicUrl($relative);
    }

    /**
     * Exact match against media_path and overlay asset refs this story stored.
     * Substring search is unsafe: a text overlay or stolen path would match.
     * Overlay files must also use this story owner's upload filename prefix so a
     * client cannot mint a signed URL for someone else's private sticker path.
     */
    private function storyOwnsAssetPath(Story $story, string $relative): bool
    {
        $relative = ltrim($relative, '/');
        if ($relative === '' || str_contains($relative, '..') || ! str_starts_with($relative, self::STORIES_DIR.'/')) {
            return false;
        }

        $media = ltrim((string) $story->media_path, '/');
        if ($media !== '' && hash_equals($media, $relative)) {
            return true;
        }

        $basename = basename($relative);
        $ownedPrefix = '/^(sticker|boom|audio)_'.(int) $story->user_id.'_/';
        foreach ($this->storyOwnedPrivatePaths($story) as $owned) {
            if (hash_equals($owned, $relative) && preg_match($ownedPrefix, $basename) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function storyOwnedPrivatePaths(Story $story): array
    {
        $paths = [];
        $media = ltrim((string) $story->media_path, '/');
        if ($media !== '' && str_starts_with($media, self::STORIES_DIR.'/')) {
            $paths[$media] = true;
        }

        foreach ($this->overlayStoredAssetRefs($story->overlays) as $ref) {
            $extracted = $this->extractPrivateAssetPath($ref);
            if ($extracted !== null) {
                $paths[$extracted] = true;
            }
        }

        return array_keys($paths);
    }

    /**
     * Overlay fields that may hold uploaded story files (not text, mentions, etc.).
     *
     * @return list<string>
     */
    private function overlayStoredAssetRefs(mixed $overlays): array
    {
        if (! is_array($overlays)) {
            return [];
        }

        $refs = [];
        foreach ($overlays as $o) {
            if (! is_array($o)) {
                continue;
            }
            foreach (['preview_url', 'image_url', 'url'] as $key) {
                if (! empty($o[$key]) && is_string($o[$key])) {
                    $refs[] = $o[$key];
                }
            }
            if (! empty($o['frames']) && is_array($o['frames'])) {
                foreach ($o['frames'] as $frame) {
                    if (is_string($frame) && $frame !== '') {
                        $refs[] = $frame;
                    }
                }
            }
            if (! empty($o['cells']) && is_array($o['cells'])) {
                foreach ($o['cells'] as $cell) {
                    if (is_array($cell) && ! empty($cell['image_url']) && is_string($cell['image_url'])) {
                        $refs[] = $cell['image_url'];
                    }
                }
            }
        }

        return $refs;
    }
}
