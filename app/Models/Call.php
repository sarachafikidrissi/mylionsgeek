<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Call extends Model
{
    use HasFactory;

    public const TYPE_AUDIO = 'audio';
    public const TYPE_VIDEO = 'video';

    /** Ringing / waiting for answer (legacy alias: pending). */
    public const STATUS_RINGING = 'ringing';
    public const STATUS_PENDING = 'ringing';

    /** Accepted and media may be connected (legacy alias: ongoing). */
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_ONGOING = 'accepted';

    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_MISSED = 'missed';
    public const STATUS_ENDED = 'ended';

    public const RING_TIMEOUT_SECONDS = 45;

    /**
     * Auto-end accepted calls with no hangup after this long (crash / force-quit safety).
     * Long enough for legitimate meetings; still clears abandoned rows.
     */
    public const ACCEPTED_TIMEOUT_SECONDS = 28800;

    protected $fillable = [
        'caller_id',
        'callee_id',
        'channel_name',
        'type',
        'status',
        'started_at',
        'answered_at',
        'ended_at',
        'duration',
        'voip_uuid',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration' => 'integer',
        ];
    }

    public function caller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caller_id');
    }

    public function callee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'callee_id');
    }

    public function isParticipant(int $userId): bool
    {
        return (int) $this->caller_id === $userId || (int) $this->callee_id === $userId;
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_RINGING, self::STATUS_ACCEPTED], true);
    }

    public function isRinging(): bool
    {
        return $this->status === self::STATUS_RINGING;
    }

    public function scopeRinging($query)
    {
        return $query->where('status', self::STATUS_RINGING);
    }

    public function scopePending($query)
    {
        return $this->scopeRinging($query);
    }

    public function scopeAccepted($query)
    {
        return $query->where('status', self::STATUS_ACCEPTED);
    }

    public function scopeOngoing($query)
    {
        return $this->scopeAccepted($query);
    }

    public function scopeEnded($query)
    {
        return $query->where('status', self::STATUS_ENDED);
    }

    public function scopeMissed($query)
    {
        return $query->where('status', self::STATUS_MISSED);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where(function ($q) use ($userId) {
            $q->where('caller_id', $userId)->orWhere('callee_id', $userId);
        });
    }
}
