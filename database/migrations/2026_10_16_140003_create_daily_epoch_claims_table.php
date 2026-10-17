<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_epoch_claims', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->date('epoch_date');
            $table->unsignedInteger('activity_score');
            $table->unsignedInteger('allocated_bytes');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['user_id', 'epoch_date']);
            $table->index('epoch_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_epoch_claims');
    }
};
