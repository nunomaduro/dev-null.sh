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
            $table->string('giphy_id', 64)->nullable();
            $table->text('giphy_url')->nullable();
            $table->text('giphy_page_url')->nullable();
            $table->string('giphy_title', 300)->nullable();
            $table->string('giphy_username', 100)->nullable();
            $table->unsignedSmallInteger('giphy_width')->nullable();
            $table->unsignedSmallInteger('giphy_height')->nullable();
        });
    }
};
