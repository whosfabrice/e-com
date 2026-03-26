<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_daily_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_daily_entity_id')->constrained('ad_daily_entities')->cascadeOnDelete();
            $table->string('source');
            $table->string('metric');
            $table->decimal('value', 14, 4);
            $table->timestamps();

            $table->unique(['ad_daily_entity_id', 'source', 'metric'], 'ad_daily_metrics_unique');
            $table->index(['source', 'metric'], 'ad_daily_metrics_source_metric');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_daily_metrics');
    }
};
