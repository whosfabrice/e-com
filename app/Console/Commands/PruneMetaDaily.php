<?php

namespace App\Console\Commands;

use App\Models\AdDailyEntity;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class PruneMetaDaily extends Command
{
    protected $signature = 'report:prune-meta-daily {--days=180 : Retention period in days}';

    protected $description = 'Delete old stored daily ad reporting rows beyond the configured retention period.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = Carbon::now('Europe/Berlin')->subDays($days)->toDateString();

        $deleted = AdDailyEntity::query()
            ->where('date', '<', $cutoff)
            ->delete();

        $this->info(sprintf(
            'Pruned %d ad daily entit%s older than %s.',
            $deleted,
            $deleted === 1 ? 'y' : 'ies',
            $cutoff,
        ));

        return self::SUCCESS;
    }
}
