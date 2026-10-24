<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An org's maintainers pin messages for everyone in it, so the thread needs to know which messages are pinned and
     * in what order, latest first. Every member of the org sees it, whatever history they were given. It is cleared
     * when the message is unpinned, and goes with the message.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->timestamp('pinned_at')->nullable();

            $table->index(['conversation_id', 'pinned_at']);
        });
    }
};
