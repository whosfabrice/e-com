<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_daily_entities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('platform');
            $table->date('date');
            $table->string('ad_id');
            $table->string('ad_name');
            $table->string('campaign_id')->nullable();
            $table->string('campaign_name')->nullable();
            $table->string('creative_id')->nullable();
            $table->text('thumbnail_url')->nullable();
            $table->timestamps();

            $table->unique(['brand_id', 'platform', 'date', 'ad_id'], 'ad_daily_entities_unique');
            $table->index(['brand_id', 'platform', 'date'], 'ad_daily_entities_brand_platform_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_daily_entities');
    }
};
