<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Only the few people live right now have an end time, so the live list and the job that ends streams read just
     * them instead of every user.
     */
    public function up(): void
    {
        $live = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) ? '' : 'where live_ends_at is not null';

        DB::statement("create index users_live_ends_at_index on users (live_ends_at) {$live}");
    }
};
