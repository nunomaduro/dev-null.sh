<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\ListApiCommitRepliesRequest;
use App\Http\Resources\CommitResource;
use App\Models\Commit;
use App\Models\User;
use App\Queries\CommitRepliesQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class CommitReplyController
{
    public function index(ListApiCommitRepliesRequest $request, #[CurrentUser] User $user, Commit $commit, CommitRepliesQuery $replies): AnonymousResourceCollection
    {
        return CommitResource::collection($replies->paginate($commit, $user));
    }
}
