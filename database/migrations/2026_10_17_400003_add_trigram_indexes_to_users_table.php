<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * People search and mention suggestions match any part of the lowercase username or name, as someone types.
     * Trigram indexes find those matches for terms of three characters or more instead of reading every user.
     */
    public function up(): void
    {
        Schema::ensureExtensionExists('pg_trgm');

        Schema::table('users', function (Blueprint $table): void {
            $table->rawIndex('lower(username) gin_trgm_ops', 'users_lower_username_trigram_index')->algorithm('gin');
            $table->rawIndex('lower(name) gin_trgm_ops', 'users_lower_name_trigram_index')->algorithm('gin');
        });
    }
};
