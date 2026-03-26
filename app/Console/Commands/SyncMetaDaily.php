<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Services\Meta\MetaDailySyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SyncMetaDaily extends Command
{
    protected $signature = 'report:sync-meta-daily {brand? : Optional brand handle} {--days=3 : Number of completed days to refresh}';

    protected $description = 'Sync recent completed Meta ad reporting days into the local reporting tables.';

    public function handle(MetaDailySyncService $metaDailySyncService): int
    {
        $brandHandle = $this->argument('brand');
        $days = max(1, (int) $this->option('days'));
        $until = Carbon::now('Europe/Berlin')->subDay();
        $since = $until->copy()->subDays($days - 1);

        $brands = $brandHandle
            ? Brand::query()->where('handle', $brandHandle)->get()
            : Brand::query()->get();

        foreach ($brands as $brand) {
            try {
                $result = $metaDailySyncService->sync($brand, $since, $until);

                $this->info(sprintf(
                    'Synced %s for %s (%s to %s, %d entities, %d metrics).',
                    $brand->name,
                    $days.' day'.($days === 1 ? '' : 's'),
                    $result['since'],
                    $result['until'],
                    $result['entity_count'],
                    $result['metric_count'],
                ));
            } catch (\Throwable $throwable) {
                $this->error(sprintf('Failed syncing %s: %s', $brand->name, $throwable->getMessage()));
            }
        }

        return self::SUCCESS;
    }
}
