<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Counting the stars a user received from others checks who gave each star, so with the user beside the commit
     * the count reads the index alone. It covers every lookup the commit index it replaces did.
     */
    public function up(): void
    {
        Schema::table('stars', function (Blueprint $table): void {
            $table->index(['commit_id', 'user_id']);
            $table->dropIndex(['commit_id']);
        });
    }
};
