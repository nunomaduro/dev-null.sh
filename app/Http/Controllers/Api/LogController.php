<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\Log;
use App\Http\Resources\CommitResource;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class LogController
{
    public function show(#[CurrentUser] User $user, Log $log): AnonymousResourceCollection
    {
        return CommitResource::collection($log->query()->builder($user)->cursorPaginate(20));
    }
}
