<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_phase4_creatives', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('campaign_id');
            $table->string('campaign_name');
            $table->string('ad_id');
            $table->string('creative_id');
            $table->timestamps();

            $table->unique(['brand_id', 'ad_id']);
            $table->index(['brand_id', 'creative_id']);
            $table->index(['brand_id', 'campaign_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_phase4_creatives');
    }
};
