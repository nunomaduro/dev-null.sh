<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Profiles, previews and the activity graph count a user's commits as the viewer sees them, by status and without
     * the deleted ones. With both in the index, those counts read the index alone instead of every commit of the user.
     */
    public function up(): void
    {
        $include = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) ? '' : 'include (status, deleted_at)';

        Schema::table('commits', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'created_at']);
        });

        DB::statement("create index commits_user_id_created_at_index on commits (user_id, created_at) {$include}");
    }
};
