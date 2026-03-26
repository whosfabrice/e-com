<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Services\Meta\MetaDailySyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class BackfillMetaDaily extends Command
{
    protected $signature = 'report:backfill-meta {brand? : Optional brand handle} {--from= : Start date YYYY-MM-DD} {--to= : End date YYYY-MM-DD} {--chunk=30 : Chunk size in days}';

    protected $description = 'Backfill historical Meta ad reporting data into the local reporting tables.';

    public function handle(MetaDailySyncService $metaDailySyncService): int
    {
        $brandHandle = $this->argument('brand');
        $chunkSize = max(1, (int) $this->option('chunk'));
        $from = Carbon::parse((string) $this->option('from'), 'Europe/Berlin')->startOfDay();
        $to = Carbon::parse((string) $this->option('to'), 'Europe/Berlin')->startOfDay();

        if ($from->gt($to)) {
            $this->error('The --from date must be on or before the --to date.');

            return self::FAILURE;
        }

        $brands = $brandHandle
            ? Brand::query()->where('handle', $brandHandle)->get()
            : Brand::query()->get();

        foreach ($brands as $brand) {
            $windowStart = $from->copy();

            while ($windowStart->lte($to)) {
                $windowEnd = $windowStart->copy()->addDays($chunkSize - 1);

                if ($windowEnd->gt($to)) {
                    $windowEnd = $to->copy();
                }

                try {
                    $result = $metaDailySyncService->sync($brand, $windowStart, $windowEnd);

                    $this->info(sprintf(
                        'Backfilled %s from %s to %s (%d entities, %d metrics).',
                        $brand->name,
                        $result['since'],
                        $result['until'],
                        $result['entity_count'],
                        $result['metric_count'],
                    ));
                } catch (\Throwable $throwable) {
                    $this->error(sprintf(
                        'Failed backfilling %s from %s to %s: %s',
                        $brand->name,
                        $windowStart->toDateString(),
                        $windowEnd->toDateString(),
                        $throwable->getMessage(),
                    ));

                    return self::FAILURE;
                }

                $windowStart = $windowEnd->copy()->addDay();
            }
        }

        return self::SUCCESS;
    }
}
