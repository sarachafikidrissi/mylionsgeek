<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoryReportNotification extends Model
{
    protected $fillable = [
        'notified_user_id',
        'story_report_id',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function storyReport(): BelongsTo
    {
        return $this->belongsTo(StoryReport::class, 'story_report_id');
    }
}
