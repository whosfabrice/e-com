<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('targets', function (Blueprint $table): void {
            $table->dropUnique(['brand_id', 'platform', 'metric']);
        });

        Schema::table('targets', function (Blueprint $table): void {
            $table->enum('metric', ['cpa', 'purchases'])->change();
        });

        Schema::table('targets', function (Blueprint $table): void {
            $table->unique(['brand_id', 'platform', 'metric']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('targets', function (Blueprint $table): void {
            $table->dropUnique(['brand_id', 'platform', 'metric']);
        });

        DB::table('targets')->where('metric', 'purchases')->delete();

        Schema::table('targets', function (Blueprint $table): void {
            $table->enum('metric', ['cpa'])->change();
        });

        Schema::table('targets', function (Blueprint $table): void {
            $table->unique(['brand_id', 'platform', 'metric']);
        });
    }
};
