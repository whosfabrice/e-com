<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_daily_entities', function (Blueprint $table): void {
            $table->text('ad_name')->change();
            $table->text('campaign_name')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('ad_daily_entities', function (Blueprint $table): void {
            $table->string('ad_name')->change();
            $table->string('campaign_name')->nullable()->change();
        });
    }
};
