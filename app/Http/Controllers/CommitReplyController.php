<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateCommit;
use App\Actions\DeleteCommit;
use App\Enums\CommitStatus;
use App\Http\Requests\CreateCommitReplyRequest;
use App\Http\Requests\DeleteCommitReplyRequest;
use App\Http\Requests\ListCommitRepliesRequest;
use App\Models\Commit;
use App\Models\User;
use App\Queries\CommitRepliesQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final readonly class CommitReplyController
{
    public function index(ListCommitRepliesRequest $request, #[CurrentUser] ?User $user, Commit $commit, CommitRepliesQuery $replies): JsonResponse
    {
        return response()->json($replies->paginate($commit, $user, $request->boolean('newest'), $request->integer('limit', CommitRepliesQuery::PER_PAGE)));
    }

    public function store(CreateCommitReplyRequest $request, #[CurrentUser] User $user, Commit $commit, CreateCommit $action): JsonResponse
    {
        $reply = $action->handle($user, $request->string('body')->value(), $commit, images: $request->images(), imageDescriptions: $request->imageDescriptions(), linkPreview: $request->linkPreview(), giphy: $request->giphy());

        return response()->json([
            'committed' => [
                'id' => $reply->id,
                'body' => $reply->body,
            ],
            ...$reply->status === CommitStatus::Pending ? ['toast' => [
                'type' => 'info',
                'message' => __('Your commit is pending review. Everyone will see it once it is approved.'),
            ]] : [],
        ], 201);
    }

    public function destroy(DeleteCommitReplyRequest $request, Commit $commit, Commit $reply, DeleteCommit $action): JsonResponse
    {
        $reply = $action->handle($reply);

        return response()->json(['deleted' => [
            'id' => $reply->id,
            'parent_id' => $reply->parent_id,
            'deleted_at' => $reply->deleted_at?->toJSON(),
            'expires_at' => $reply->deleted_at?->addSeconds(Commit::RESTORABLE_SECONDS)->toJSON(),
            'server_time' => now()->toJSON(),
        ]]);
    }
}
