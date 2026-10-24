<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('topics', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('value', 80);
            $table->timestamp('last_applied_at')->index();
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX topics_value_unique ON topics (LOWER(value))');

        Schema::create('commit_topic', function (Blueprint $table): void {
            $table->foreignUuid('commit_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('topic_id')->constrained()->cascadeOnDelete();
            $table->unique(['commit_id', 'topic_id']);
            $table->index('topic_id');
        });
    }
};
