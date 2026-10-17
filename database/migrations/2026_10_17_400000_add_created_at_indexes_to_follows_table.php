<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Followers and following lists show the latest follows first, so these indexes read a page of them in order
     * instead of sorting every follow of the user. The followed index covers every lookup the one it replaces did.
     */
    public function up(): void
    {
        Schema::table('follows', function (Blueprint $table): void {
            $table->index(['followed_id', 'created_at']);
            $table->index(['follower_id', 'created_at']);
            $table->dropIndex(['followed_id']);
        });
    }
};
