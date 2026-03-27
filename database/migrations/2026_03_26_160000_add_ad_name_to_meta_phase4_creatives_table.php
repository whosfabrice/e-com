<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_phase4_creatives', function (Blueprint $table): void {
            $table->string('ad_name')->nullable()->after('ad_id');
            $table->index(['brand_id', 'ad_name']);
        });
    }

    public function down(): void
    {
        Schema::table('meta_phase4_creatives', function (Blueprint $table): void {
            $table->dropIndex(['brand_id', 'ad_name']);
            $table->dropColumn('ad_name');
        });
    }
};
