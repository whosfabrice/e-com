<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Services\Meta\MetaPhase4CreativeSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncMetaPhase4Creatives extends Command
{
    protected $signature = 'report:sync-meta-phase4 {brand? : Optional brand handle}';

    protected $description = 'Sync active Meta Phase 4 campaign creatives into local membership storage.';

    public function handle(MetaPhase4CreativeSyncService $syncService): int
    {
        $brandHandle = $this->argument('brand');

        $brands = $brandHandle
            ? Brand::query()->where('handle', $brandHandle)->get()
            : Brand::query()->get();

        foreach ($brands as $brand) {
            try {
                $result = $syncService->sync($brand);

                $this->info(sprintf(
                    'Synced Phase 4 creatives for %s (%d campaigns, %d rows).',
                    $brand->name,
                    $result['campaign_count'],
                    $result['creative_count'],
                ));
            } catch (\Throwable $throwable) {
                Log::error('Phase 4 creative sync failed.', [
                    'brand_id' => $brand->id,
                    'brand_name' => $brand->name,
                    'brand_handle' => $brand->handle,
                    'message' => $throwable->getMessage(),
                ]);
                $this->error(sprintf('Failed syncing Phase 4 creatives for %s: %s', $brand->name, $throwable->getMessage()));
            }
        }

        return self::SUCCESS;
    }
}
