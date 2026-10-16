<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commit_impression_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('batch_id');
            $table->timestamp('created_at')->index();

            $table->unique(['user_id', 'batch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commit_impression_batches');
    }
};
