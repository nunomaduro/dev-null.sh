<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\DeleteScheduledCommit;
use App\Actions\UpdateScheduledCommit;
use App\Exceptions\ApiException;
use App\Http\Requests\DeleteApiScheduledCommitRequest;
use App\Http\Requests\UpdateApiScheduledCommitRequest;
use App\Http\Resources\ScheduledCommitResource;
use App\Models\ScheduledCommit;
use App\Models\User;
use App\Queries\ScheduledCommitsQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final readonly class ScheduledCommitController
{
    /**
     * The user's scheduled commits, as the web's Scheduled commits page lists them: the ones still waiting first, then
     * the submitted ones, 20 a page.
     */
    public function index(#[CurrentUser] User $user, ScheduledCommitsQuery $scheduledCommits): AnonymousResourceCollection
    {
        return ScheduledCommitResource::collection($scheduledCommits->builder($user)->paginate(20));
    }

    /**
     * Answers with the scheduled commit as it now is. Once it was submitted or canceled, it is a
     * scheduled_commit_submitted error, and nothing changes.
     */
    public function update(UpdateApiScheduledCommitRequest $request, #[CurrentUser] User $user, ScheduledCommit $scheduledCommit, UpdateScheduledCommit $action): ScheduledCommitResource|JsonResponse
    {
        if (! $action->handle($user, $scheduledCommit, $request->string('body')->value(), $request->scheduledAt())) {
            return ApiException::scheduledCommitSubmitted()->render();
        }

        return ScheduledCommitResource::make($scheduledCommit->refresh());
    }

    /**
     * Cancels the scheduled commit, as the web's Cancel does, so it is never submitted. Once it was submitted or
     * canceled, it is a scheduled_commit_submitted error.
     */
    public function destroy(DeleteApiScheduledCommitRequest $request, #[CurrentUser] User $user, ScheduledCommit $scheduledCommit, DeleteScheduledCommit $action): Response|JsonResponse
    {
        if (! $action->handle($user, $scheduledCommit)) {
            return ApiException::scheduledCommitSubmitted()->render();
        }

        return response()->noContent();
    }
}
