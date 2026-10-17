<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('remotes_enabled')->default(false);
            $table->string('remotes_from')->default('following');
            $table->boolean('pull_receipts')->default(true);
        });
    }
};
