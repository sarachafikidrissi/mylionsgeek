<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoryNotification extends Model
{
    public const TYPE_MENTION = 'mention';

    protected $fillable = [
        'user_id',
        'sender_id',
        'story_id',
        'type',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }
}
