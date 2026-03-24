<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->string('slack_channel_id')->nullable()->after('meta_ad_account_id');
            $table->dropColumn('slack_channel_webhook_url');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->string('slack_channel_webhook_url')->nullable()->after('meta_ad_account_id');
            $table->dropColumn('slack_channel_id');
        });
    }
};
