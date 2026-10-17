<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::ensureVectorExtensionExists();

        Schema::create('knowledge_base_sections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source');
            $table->unsignedInteger('position');
            $table->json('headings');
            $table->text('content');
            $table->vector('embedding', dimensions: 1536);
            $table->timestamps();

            $table->unique(['source', 'position']);
            $table->vectorIndex('embedding');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_base_sections');
    }
};
