<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authorization_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash')->unique();
            $table->string('fingerprint');
            $table->string('device_name');
            $table->string('code_challenge');
            $table->string('redirect_uri');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index('user_id');
        });
    }
};
