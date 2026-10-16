<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commit_tips', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('commit_id')->constrained('commits')->cascadeOnDelete();
            $table->unsignedInteger('amount');
            $table->string('gift_type', 32);
            $table->string('message', 140)->nullable();
            $table->timestamps();

            $table->index('commit_id');
            $table->index('recipient_id');
            $table->index('sender_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commit_tips');
    }
};
