<?php

namespace App\Console\Commands;

use App\Services\CallService;
use Illuminate\Console\Command;

class MarkMissedCalls extends Command
{
    protected $signature = 'calls:mark-missed';

    protected $description = 'Mark timed-out ringing calls as missed and end abandoned accepted calls';

    public function handle(CallService $callService): int
    {
        $result = $callService->cleanupStaleCalls();
        $this->info("Marked {$result['missed']} missed call(s), ended {$result['ended']} stale accepted call(s).");

        return self::SUCCESS;
    }
}
