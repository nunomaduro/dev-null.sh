<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shadows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('name', 32)->default('daemon-0');
            $table->string('glyph', 16)->default('λ');
            $table->string('skin', 32)->default('classic-green');
            $table->string('title', 64)->nullable();
            $table->json('unlocked_skins');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shadows');
    }
};
