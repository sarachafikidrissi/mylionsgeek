<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Story extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'media_path',
        'media_type',
        'audience',
        'bg_color',
        'overlays',
        'duration_ms',
        'width',
        'height',
        'expires_at',
        'is_hidden',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'duration_ms' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'overlays' => 'array',
        'is_hidden' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function views(): HasMany
    {
        return $this->hasMany(StoryView::class);
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(StoryReaction::class);
    }

    public function highlightItems(): HasMany
    {
        return $this->hasMany(StoryHighlightItem::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(StoryReport::class);
    }

    public function interactions(): HasMany
    {
        return $this->hasMany(StoryInteraction::class);
    }

    public function scopeActive($query)
    {
        return $query->where('expires_at', '>', now());
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Owner always sees their story. Public stories are visible to any
     * authenticated user. Close-friends stories require a close_friends row.
     */
    public function isVisibleTo(?User $viewer): bool
    {
        if (!$viewer) {
            return false;
        }
        if ((int) $this->user_id === (int) $viewer->id) {
            return true;
        }

        if ($this->is_hidden) {
            return false;
        }

        if (in_array((int) $this->user_id, $viewer->excludedAuthorIds(), true)) {
            return false;
        }

        $audience = $this->audience ?: 'public';
        if ($audience === 'public') {
            return true;
        }
        if ($audience === 'close_friends') {
            return CloseFriend::query()
                ->where('user_id', $this->user_id)
                ->where('friend_id', $viewer->id)
                ->exists();
        }

        return false;
    }

    /**
     * Feed expiration is query-scoped (`scopeActive`). Rows are kept so the
     * owner can use Archive / Highlights. This method is a no-op retained
     * for the scheduled command.
     */
    public static function purgeExpired(int $limit = 50): int
    {
        return 0;
    }
}
