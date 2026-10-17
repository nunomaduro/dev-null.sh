<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A small marker makes feed serialization O(1), with no extra query.
        Schema::table('commits', function (Blueprint $table): void {
            $table->string('signer_fingerprint', 100)->nullable();
        });

        // Original signed bytes are stored off the hot path (log/feed queries).
        Schema::create('commit_signatures', function (Blueprint $table): void {
            $table->foreignUuid('commit_id')->primary()->constrained('commits')->cascadeOnDelete();
            $table->text('payload');
            $table->text('signature');
            $table->text('public_key');
            $table->string('fingerprint', 100);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commit_signatures');

        Schema::table('commits', function (Blueprint $table): void {
            $table->dropColumn('signer_fingerprint');
        });
    }
};
