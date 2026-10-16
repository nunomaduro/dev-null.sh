<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('level_reward_claims', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('level');
            $table->unsignedInteger('bytes');
            $table->timestamps();

            $table->unique(['user_id', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('level_reward_claims');
    }
};
