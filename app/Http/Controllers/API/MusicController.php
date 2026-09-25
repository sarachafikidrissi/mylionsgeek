<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Music API for the story creator.
 *
 * Primary endpoint:
 *   GET /api/mobile/music/browse?section=top_morocco|search|trending|original&country=MA&q=&limit=50
 *
 * Legacy (still supported):
 *   GET /api/mobile/music/search?q=
 *   GET /api/mobile/music/charts?country=MA
 */
class MusicController extends Controller
{
    private const SPOTIFY_TOKEN_CACHE_KEY = 'spotify:client_credentials_token';
    private const HTTP_TIMEOUT            = 10;
    private const MAX_LIMIT               = 50;
    private const PREVIEW_MAX_MS          = 30_000;
    private const STORY_MAX_MS            = 60_000;

    private const CHART_PLAYLISTS = [
        'MA' => '37i9dQZEVXb011m45eRyH0', // Top 50 – Morocco
        'US' => '37i9dQZF1DX0XUsuxWHRQd',
    ];

    /**
     * Morocco trending / viral editorial playlists (tried in order).
     *   - Viral 50 – Morocco
     *   - Hot Hits Morocco ("les hits du moment au Maroc")
     */
    private const TRENDING_PLAYLISTS = [
        'MA' => [
            '37i9dQZEVXbMDoHDOPyNHz', // Viral 50 – Morocco
            '37i9dQZF1DWYHO8PTSQ9fM', // Hot Hits Morocco
        ],
    ];

    private const SECTION_QUERIES = [
        'original' => 'original audio',
    ];

    private const BROWSE_CACHE_TTL_SECONDS = 1200; // 20 minutes for charts / trending
    private const SEARCH_CACHE_TTL_SECONDS = 300;  // 5 minutes for search

    /** Curated Moroccan artists used when Spotify is unavailable. */
    private const MOROCCO_ARTIST_QUERIES = [
        'ElGrandeToto',
        '7liwa',
        'Draganov',
        'Stormy',
        'Tagne',
        'Manal',
        'Lbenj',
        'Najm',
        'Inkonnu',
        'Samara',
        'Shobee',
        'Figoshin',
        'Mons',
        'Small X',
        'Ayed',
    ];

    // ─── Public endpoints ─────────────────────────────────────────────────

    /**
     * Unified music browse — charts, search, and category feeds.
     */
    public function browse(Request $request)
    {
        $user = $this->requireUser();
        if ($user instanceof \Illuminate\Http\JsonResponse) {
            return $user;
        }

        $section = (string) $request->query('section', 'top_morocco');
        $country = strtoupper((string) $request->query('country', config('services.spotify.market', 'MA')));
        $limit   = $this->clampLimit((int) $request->query('limit', 50));
        $query   = trim((string) $request->query('q', ''));

        return response()->json($this->resolveBrowseSection($section, $country, $limit, $query));
    }

    /** @deprecated Use browse?section=search */
    public function search(Request $request)
    {
        $user = $this->requireUser();
        if ($user instanceof \Illuminate\Http\JsonResponse) {
            return $user;
        }

        $query   = trim((string) $request->input('q', ''));
        $limit   = $this->clampLimit((int) $request->input('limit', 50));
        $country = strtoupper((string) $request->input('country', config('services.spotify.market', 'MA')));

        if ($query === '') {
            return response()->json($this->emptyPayload('search', 'Search', $country));
        }

        return response()->json($this->loadSearch($query, $limit, $country, 'Search results', $country));
    }

    /** @deprecated Use browse?section=top_morocco */
    public function charts(Request $request)
    {
        $user = $this->requireUser();
        if ($user instanceof \Illuminate\Http\JsonResponse) {
            return $user;
        }

        $country = strtoupper((string) $request->input('country', config('services.spotify.market', 'MA')));
        $limit   = $this->clampLimit((int) $request->input('limit', 50));

        return response()->json($this->loadTopMorocco($country, $limit));
    }

    public function lyrics(Request $request)
    {
        $user = $this->requireUser();
        if ($user instanceof \Illuminate\Http\JsonResponse) {
            return $user;
        }

        return response()->json([
            'lyrics' => null,
            'source' => 'none',
            'message' => 'Third-party lyrics are not attached to Stories.',
        ]);
    }

    // ─── Browse resolver ──────────────────────────────────────────────────

    private function resolveBrowseSection(string $section, string $country, int $limit, string $query): array
    {
        if ($section === 'search' && $query === '') {
            return $this->emptyPayload('search', 'Search', $country);
        }

        $ttl = $section === 'search' ? self::SEARCH_CACHE_TTL_SECONDS : self::BROWSE_CACHE_TTL_SECONDS;
        $cacheKey = 'music:browse:'.md5(implode('|', [
            strtolower($section),
            strtoupper($country),
            (string) $limit,
            mb_strtolower($query),
        ]));

        return Cache::remember($cacheKey, $ttl, function () use ($section, $country, $limit, $query) {
            return $this->loadBrowseSection($section, $country, $limit, $query);
        });
    }

    private function loadBrowseSection(string $section, string $country, int $limit, string $query): array
    {
        if ($section === 'top_morocco') {
            return $this->loadTopMorocco($country, $limit);
        }

        if ($section === 'search') {
            return $this->loadSearch($query, $limit, $country, 'Search results', $country);
        }

        if ($section === 'trending' || $section === 'trending_morocco') {
            return $this->loadTrendingMorocco($country, $limit);
        }

        if (isset(self::SECTION_QUERIES[$section])) {
            return $this->loadSearch(
                self::SECTION_QUERIES[$section],
                $limit,
                $country,
                ucfirst($section),
                $country
            );
        }

        return $this->loadTopMorocco($country, $limit);
    }

    private function loadTrendingMorocco(string $country, int $limit): array
    {
        $country    = $country ?: 'MA';
        $title      = $country === 'MA' ? 'Tendance au Maroc' : "Trending in {$country}";
        $market     = $country ?: (string) config('services.spotify.market', 'MA');
        $playlistIds = self::TRENDING_PLAYLISTS[$country] ?? self::TRENDING_PLAYLISTS['MA'];

        $token = $this->isSpotifyConfigured() ? $this->getSpotifyToken() : null;
        if ($token) {
            foreach ($playlistIds as $playlistId) {
                try {
                    $items = $this->spotifyPlaylistTracks($token, $playlistId, $limit, $market);
                    if (count($items) === 0) continue;
                    $items = $this->enrichTracks($items);
                    return $this->buildPayload('trending', $title, $country, 'spotify+itunes', $items);
                } catch (Throwable $e) {
                    Log::warning("Spotify trending playlist {$playlistId} failed: " . $e->getMessage());
                }
            }
        }

        // Fallback: Spotify search scoped to Morocco market + iTunes.
        $items = $this->enrichTracks($this->itunesMoroccoCurated($limit));
        if (count($items) > 0) {
            return $this->buildPayload('trending', $title, $country, 'itunes', $items);
        }

        return $this->emptyPayload('trending', $title, $country);
    }

    private function loadTopMorocco(string $country, int $limit): array
    {
        $title      = $country === 'MA' ? 'Top Maroc' : "Top {$country}";
        $playlistId = self::CHART_PLAYLISTS[$country] ?? self::CHART_PLAYLISTS['MA'];
        $market     = $country ?: (string) config('services.spotify.market', 'MA');

        $token = $this->isSpotifyConfigured() ? $this->getSpotifyToken() : null;
        if ($token) {
            try {
                $items = $this->spotifyPlaylistTracks($token, $playlistId, $limit, $market);
                $items = $this->enrichTracks($items);
                return $this->buildPayload('top_morocco', $title, $country, 'spotify+itunes', $items);
            } catch (Throwable $e) {
                Log::warning('Spotify charts failed: ' . $e->getMessage());
            }
        }

        $items = $this->enrichTracks($this->itunesMoroccoCurated($limit));
        if (count($items) > 0) {
            return $this->buildPayload('top_morocco', $title, $country, 'itunes', $items);
        }

        return $this->emptyPayload('top_morocco', $title, $country);
    }

    private function loadSearch(string $query, int $limit, string $country, string $title, ?string $marketOverride = null): array
    {
        try {
            $market = $marketOverride ?: (string) config('services.spotify.market', $country ?: 'MA');
            $items  = $this->loadSearchItems($query, $limit, $market);
            return $this->buildPayload('search', $title, $country, 'spotify+itunes', $items);
        } catch (Throwable $e) {
            try {
                $items = $this->enrichTracks($this->itunesSearch($query, $limit));
                return $this->buildPayload('search', $title, $country, 'itunes', $items);
            } catch (Throwable $inner) {
                return $this->emptyPayload('search', $title, $country);
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadSearchItems(string $query, int $limit, string $market): array
    {
        $token = $this->isSpotifyConfigured() ? $this->getSpotifyToken() : null;

        if ($token) {
            try {
                $items = $this->spotifySearch($token, $query, $limit, $market);
                return $this->enrichTracks($items);
            } catch (Throwable $e) {
                Log::warning('Spotify search failed: ' . $e->getMessage());
            }
        }

        return $this->enrichTracks($this->itunesSearch($query, $limit));
    }

    private function buildPayload(string $section, string $title, ?string $country, string $source, array $items): array
    {
        return [
            'section'          => $section,
            'title'            => $title,
            'country'          => $country,
            'source'           => $source,
            'items'            => array_values($items),
            'total'            => count($items),
            'preview_max_ms'   => self::PREVIEW_MAX_MS,
            'story_max_ms'     => self::STORY_MAX_MS,
        ];
    }

    private function emptyPayload(string $section, string $title, ?string $country): array
    {
        return $this->buildPayload($section, $title, $country, $this->isSpotifyConfigured() ? 'spotify+itunes' : 'itunes', []);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function enrichTracks(array $items): array
    {
        $items = $this->backfillFromItunes($items);
        return array_map(fn ($item) => $this->normalizeTrack($item), $items);
    }

    private function normalizeTrack(array $item): array
    {
        $durationMs = max(0, (int) ($item['duration_ms'] ?? 0));
        $hasPreview = !empty($item['preview_url']);
        $storyClip  = $durationMs > 0
            ? min($durationMs, self::STORY_MAX_MS)
            : self::STORY_MAX_MS;

        return [
            'id'                 => (string) ($item['id'] ?? ''),
            'title'              => (string) ($item['title'] ?? ''),
            'artist'             => (string) ($item['artist'] ?? ''),
            'album'              => (string) ($item['album'] ?? ''),
            'cover_url'          => $item['cover_url'] ?? null,
            'preview_url'        => $item['preview_url'] ?? null,
            'duration_ms'        => $durationMs,
            'explicit'           => (bool) ($item['explicit'] ?? false),
            'source'             => (string) ($item['source'] ?? 'spotify'),
            'has_preview'        => $hasPreview,
            'preview_max_ms'     => $hasPreview ? self::PREVIEW_MAX_MS : 0,
            'story_clip_ms'      => $storyClip,
            'default_start_ms'   => 0,
            'default_end_ms'     => $storyClip,
        ];
    }

    // ─── Spotify ────────────────────────────────────────────────────────

    private function isSpotifyConfigured(): bool
    {
        return !empty(config('services.spotify.client_id'))
            && !empty(config('services.spotify.client_secret'));
    }

    private function getSpotifyToken(): ?string
    {
        $cached = Cache::get(self::SPOTIFY_TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $clientId     = (string) config('services.spotify.client_id');
        $clientSecret = (string) config('services.spotify.client_secret');
        if ($clientId === '' || $clientSecret === '') {
            return null;
        }

        try {
            $response = Http::asForm()
                ->withHeaders(['Authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret)])
                ->timeout(self::HTTP_TIMEOUT)
                ->post('https://accounts.spotify.com/api/token', ['grant_type' => 'client_credentials']);

            if (!$response->ok()) {
                Log::warning('Spotify token failed', ['status' => $response->status()]);
                return null;
            }

            $body  = $response->json();
            $token = $body['access_token'] ?? null;
            $ttl   = (int) ($body['expires_in'] ?? 3600);
            if (!$token) return null;

            Cache::put(self::SPOTIFY_TOKEN_CACHE_KEY, $token, max(60, $ttl - 300));
            return $token;
        } catch (Throwable $e) {
            Log::warning('Spotify token error: ' . $e->getMessage());
            return null;
        }
    }

    private function spotifySearch(string $token, string $query, int $limit, string $market): array
    {
        $response = Http::withToken($token)
            ->timeout(self::HTTP_TIMEOUT)
            ->get('https://api.spotify.com/v1/search', [
                'q'      => $query,
                'type'   => 'track',
                'limit'  => $limit,
                'market' => $market,
            ]);

        if ($response->status() === 401) {
            Cache::forget(self::SPOTIFY_TOKEN_CACHE_KEY);
            throw new \RuntimeException('Spotify token expired');
        }
        if (!$response->ok()) {
            throw new \RuntimeException('Spotify search HTTP ' . $response->status());
        }

        return $this->mapSpotifyTracks($response->json('tracks.items') ?? []);
    }

    private function spotifyPlaylistTracks(string $token, string $playlistId, int $limit, string $market): array
    {
        $items   = [];
        $offset  = 0;
        $pageSize = min(50, $limit);

        while (count($items) < $limit) {
            $response = Http::withToken($token)
                ->timeout(self::HTTP_TIMEOUT)
                ->get("https://api.spotify.com/v1/playlists/{$playlistId}/tracks", [
                    'market' => $market,
                    'limit'  => $pageSize,
                    'offset' => $offset,
                    'fields' => 'items(track(id,name,duration_ms,explicit,preview_url,artists(name),album(name,images))),next',
                ]);

            if ($response->status() === 401) {
                Cache::forget(self::SPOTIFY_TOKEN_CACHE_KEY);
                throw new \RuntimeException('Spotify token expired');
            }
            if (!$response->ok()) {
                throw new \RuntimeException('Spotify playlist HTTP ' . $response->status());
            }

            $batch = $this->mapSpotifyTracks(
                array_map(fn ($row) => $row['track'] ?? null, $response->json('items') ?? [])
            );
            $items = array_merge($items, $batch);

            if (!$response->json('next') || count($batch) === 0) {
                break;
            }
            $offset += $pageSize;
        }

        return array_slice($items, 0, $limit);
    }

    private function mapSpotifyTracks(array $tracks): array
    {
        $items = [];
        foreach ($tracks as $t) {
            if (!is_array($t) || empty($t['id'])) continue;
            $images  = $t['album']['images'] ?? [];
            $cover   = $images[1]['url'] ?? ($images[0]['url'] ?? null);
            $artists = array_map(fn ($a) => $a['name'] ?? '', $t['artists'] ?? []);
            $items[] = [
                'id'          => 'spotify-' . $t['id'],
                'title'       => (string) ($t['name'] ?? ''),
                'artist'      => trim(implode(', ', array_filter($artists))),
                'album'       => (string) ($t['album']['name'] ?? ''),
                'cover_url'   => $cover,
                'preview_url' => $t['preview_url'] ?? null,
                'duration_ms' => (int) ($t['duration_ms'] ?? 0),
                'explicit'    => (bool) ($t['explicit'] ?? false),
                'source'      => 'spotify',
            ];
        }
        return $items;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function backfillFromItunes(array $items): array
    {
        foreach ($items as $i => $item) {
            $title  = (string) ($item['title']  ?? '');
            $artist = (string) ($item['artist'] ?? '');
            if ($title === '' && $artist === '') continue;

            $needsPreview  = empty($item['preview_url']);
            $needsDuration = empty($item['duration_ms']);

            if (!$needsPreview && !$needsDuration) continue;

            try {
                $match = $this->itunesMatchFor($title, $artist);
                if (!$match) continue;
                if ($needsPreview && !empty($match['preview_url'])) {
                    $items[$i]['preview_url'] = $match['preview_url'];
                    $items[$i]['source']        = ($item['source'] ?? 'spotify') . '+itunes';
                }
                if ($needsDuration && !empty($match['duration_ms'])) {
                    $items[$i]['duration_ms'] = $match['duration_ms'];
                }
                if (empty($items[$i]['cover_url']) && !empty($match['cover_url'])) {
                    $items[$i]['cover_url'] = $match['cover_url'];
                }
            } catch (Throwable $e) {
                // best-effort
            }
        }
        return $items;
    }

    // ─── iTunes ─────────────────────────────────────────────────────────

    /**
     * Build a Morocco-focused track list from popular local artists (no Spotify needed).
     *
     * @return array<int, array<string, mixed>>
     */
    private function itunesMoroccoCurated(int $limit): array
    {
        $merged = [];
        $seen   = [];

        foreach (self::MOROCCO_ARTIST_QUERIES as $artistQuery) {
            if (count($merged) >= $limit) break;
            try {
                $batch = $this->itunesSearch($artistQuery, 4);
                foreach ($batch as $track) {
                    if ($this->isJunkMoroccoMatch($track)) continue;
                    $key = mb_strtolower(($track['title'] ?? '') . '|' . ($track['artist'] ?? ''));
                    if (isset($seen[$key])) continue;
                    $seen[$key] = true;
                    $merged[]   = $track;
                    if (count($merged) >= $limit) break;
                }
            } catch (Throwable $e) {
                continue;
            }
        }

        return array_slice($merged, 0, $limit);
    }

    private function isJunkMoroccoMatch(array $item): bool
    {
        $title  = mb_strtolower(trim((string) ($item['title'] ?? '')));
        $artist = mb_strtolower(trim((string) ($item['artist'] ?? '')));

        if (str_contains($artist, 'originally performed')) return true;
        if (str_contains($artist, "morocco's band")) return true;
        if (str_contains($artist, 'karaoke')) return true;
        if ($title === 'morocco' && !preg_match('/toto|7liwa|drag|stormy|tagne|manal/i', $artist)) {
            return true;
        }

        return false;
    }

    private function itunesSearch(string $query, int $limit): array
    {
        $response = Http::timeout(self::HTTP_TIMEOUT)
            ->get('https://itunes.apple.com/search', [
                'term'   => $query,
                'media'  => 'music',
                'entity' => 'song',
                'limit'  => $limit,
                'country'=> strtolower((string) config('services.spotify.market', 'ma')),
            ]);

        if (!$response->ok()) {
            throw new \RuntimeException('iTunes search HTTP ' . $response->status());
        }

        $items = [];
        foreach ($response->json('results') ?? [] as $r) {
            if (!is_array($r)) continue;
            $items[] = $this->mapItunesResult($r);
        }
        return $items;
    }

    private function itunesMatchFor(string $title, string $artist): ?array
    {
        $term = trim($title . ' ' . $artist);
        if ($term === '') return null;

        $cacheKey = 'itunes:match:' . md5(mb_strtolower($term));
        return Cache::remember($cacheKey, now()->addHours(12), function () use ($term) {
            try {
                $response = Http::timeout(self::HTTP_TIMEOUT)
                    ->get('https://itunes.apple.com/search', [
                        'term'   => $term,
                        'media'  => 'music',
                        'entity' => 'song',
                        'limit'  => 1,
                    ]);
                if (!$response->ok()) return null;
                $r = $response->json('results.0');
                return is_array($r) ? $this->mapItunesResult($r) : null;
            } catch (Throwable $e) {
                return null;
            }
        });
    }

    private function mapItunesResult(array $r): array
    {
        $cover = $r['artworkUrl100'] ?? null;
        if (is_string($cover)) {
            $cover = str_replace('100x100bb', '300x300bb', $cover);
        }
        $preview = $r['previewUrl'] ?? null;

        return [
            'id'          => 'itunes-' . ($r['trackId'] ?? bin2hex(random_bytes(4))),
            'title'       => (string) ($r['trackName'] ?? ''),
            'artist'      => (string) ($r['artistName'] ?? ''),
            'album'       => (string) ($r['collectionName'] ?? ''),
            'cover_url'   => $cover,
            'preview_url' => $preview,
            'duration_ms' => (int) ($r['trackTimeMillis'] ?? 0),
            'explicit'    => ($r['trackExplicitness'] ?? '') === 'explicit',
            'source'      => 'itunes',
        ];
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    private function requireUser()
    {
        $user = Auth::guard('sanctum')->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }
        return $user;
    }

    private function clampLimit(int $limit): int
    {
        if ($limit < 1) return 1;
        if ($limit > self::MAX_LIMIT) return self::MAX_LIMIT;
        return $limit;
    }
}
