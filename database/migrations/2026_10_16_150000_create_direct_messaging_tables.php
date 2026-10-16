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
            $table->string('dm_policy')->default('off');
            $table->boolean('dm_read_receipts')->default(false);
        });

        Schema::create('conversations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('first_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('second_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('initiator_id')->constrained('users')->cascadeOnDelete();
            $table->string('status');
            $table->timestamp('last_message_at');
            $table->timestamps();
            $table->unique(['first_user_id', 'second_user_id']);
        });

        Schema::create('conversation_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('last_read_message_id')->default(0);
            $table->boolean('muted')->default(false);
            $table->boolean('archived')->default(false);
            $table->unique(['conversation_id', 'user_id']);
            $table->index(['user_id', 'archived']);
        });

        Schema::create('direct_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sender_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('client_id');
            $table->text('body');
            $table->timestamp('created_at');
            $table->unique(['sender_id', 'client_id']);
            $table->index(['conversation_id', 'id']);
        });

        Schema::table('notifications', function (Blueprint $table): void {
            $table->foreignUuid('conversation_id')->nullable()->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('conversation_id');
        });
        Schema::dropIfExists('direct_messages');
        Schema::dropIfExists('conversation_members');
        Schema::dropIfExists('conversations');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['dm_policy', 'dm_read_receipts']);
        });
    }
};
