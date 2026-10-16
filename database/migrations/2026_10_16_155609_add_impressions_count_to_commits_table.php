<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commits', function (Blueprint $table): void {
            $table->unsignedBigInteger('impressions_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('commits', function (Blueprint $table): void {
            $table->dropColumn('impressions_count');
        });
    }
};
