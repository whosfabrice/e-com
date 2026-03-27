<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_phase4_creatives', function (Blueprint $table): void {
            $table->dropIndex(['brand_id', 'ad_name']);
            $table->text('campaign_name')->change();
            $table->text('ad_name')->nullable()->change();
        });

        Schema::table('campaigns', function (Blueprint $table): void {
            $table->text('name')->change();
        });
    }

    public function down(): void
    {
        Schema::table('meta_phase4_creatives', function (Blueprint $table): void {
            $table->string('campaign_name')->change();
            $table->string('ad_name')->nullable()->change();
            $table->index(['brand_id', 'ad_name']);
        });

        Schema::table('campaigns', function (Blueprint $table): void {
            $table->string('name')->change();
        });
    }
};
