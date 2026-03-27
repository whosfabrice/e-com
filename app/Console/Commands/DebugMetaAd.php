<?php

namespace App\Console\Commands;

use App\Services\Meta\MetaGraphClient;
use Illuminate\Console\Command;

class DebugMetaAd extends Command
{
    protected $signature = 'report:debug-meta-ad {ad_id : Meta ad ID to inspect}';

    protected $description = 'Fetch and print raw Meta ad node data for one ad.';

    public function handle(MetaGraphClient $metaGraphClient): int
    {
        $adId = (string) $this->argument('ad_id');

        try {
            $response = $metaGraphClient->get($adId, [
                'fields' => 'id,name,creative{id,thumbnail_url}',
            ]);
        } catch (\Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
