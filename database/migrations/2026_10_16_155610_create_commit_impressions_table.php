<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commit_impressions', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('commit_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_counted_at')->nullable()->index();

            $table->unique(['user_id', 'commit_id']);
            $table->index('commit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commit_impressions');
    }
};
