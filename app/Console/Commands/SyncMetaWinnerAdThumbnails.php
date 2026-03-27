<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Services\Meta\MetaWinnerAdThumbnailSyncService;
use Illuminate\Console\Command;

class SyncMetaWinnerAdThumbnails extends Command
{
    protected $signature = 'report:sync-meta-winner-thumbnails {brand?}';

    protected $description = 'Sync stored thumbnail and creative details for current winner ads.';

    public function handle(MetaWinnerAdThumbnailSyncService $syncService): int
    {
        $brandHandle = $this->argument('brand');

        $brands = Brand::query()
            ->when(
                is_string($brandHandle) && $brandHandle !== '',
                fn ($query) => $query->where('handle', $brandHandle),
            )
            ->orderBy('name')
            ->get();

        if ($brands->isEmpty()) {
            $this->error('No brands found.');

            return self::FAILURE;
        }

        foreach ($brands as $brand) {
            try {
                $result = $syncService->sync($brand);

                $this->info(sprintf(
                    'Synced winner thumbnails for %s (%d entity rows updated).',
                    $brand->name,
                    $result['count'] ?? 0,
                ));
            } catch (\Throwable $throwable) {
                $this->error(sprintf('Failed syncing winner thumbnails for %s: %s', $brand->name, $throwable->getMessage()));
            }
        }

        return self::SUCCESS;
    }
}
