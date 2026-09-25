<?php

namespace App\Console\Commands;

use App\Models\Story;
use Illuminate\Console\Command;

class PurgeExpiredStories extends Command
{
    protected $signature = 'stories:purge-expired {--limit=200}';

    protected $description = 'No-op: expired stories stay in the owner archive; the feed already hides them';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $count = Story::purgeExpired($limit);
        $this->info("Purged {$count} expired story(ies).");

        return self::SUCCESS;
    }
}
