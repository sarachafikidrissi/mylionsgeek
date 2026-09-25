<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoryInteractionResponse extends Model
{
    protected $fillable = [
        'story_interaction_id',
        'user_id',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function interaction(): BelongsTo
    {
        return $this->belongsTo(StoryInteraction::class, 'story_interaction_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
