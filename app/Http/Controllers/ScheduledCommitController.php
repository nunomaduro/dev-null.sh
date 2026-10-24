<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateScheduledCommit;
use App\Actions\DeleteScheduledCommit;
use App\Http\Requests\CreateScheduledCommitRequest;
use App\Http\Requests\DeleteScheduledCommitRequest;
use App\Models\ScheduledCommit;
use App\Models\User;
use App\Queries\ScheduledCommitsQuery;
use App\Services\CommitBodies;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final readonly class ScheduledCommitController
{
    public function index(#[CurrentUser] User $user, CommitBodies $bodies, ScheduledCommitsQuery $scheduledCommits): Response
    {
        return Inertia::render('scheduled-commits/index', [
            'scheduledCommits' => fn (): LengthAwarePaginator => $scheduledCommits->builder($user)
                ->paginate(20)->through(fn (ScheduledCommit $scheduled): array => [
                    'id' => $scheduled->id,
                    ...($scheduled->submitted_at === null && $scheduled->body !== null
                        ? ['body' => $scheduled->body, ...$bodies->segments($scheduled->body)]
                        : ['body' => null, 'body_segments' => [], 'preview_segments' => null]),
                    'poll_options' => $scheduled->poll['options'] ?? null,
                    'image_count' => count($scheduled->images ?? []),
                    'scheduled_at' => $scheduled->scheduled_at->toIso8601String(),
                    'submitted_at' => $scheduled->submitted_at?->toIso8601String(),
                    'status' => $scheduled->submitted_at === null ? 'scheduled' : ($scheduled->commit === null || $scheduled->commit->trashed() ? 'deleted' : $scheduled->commit->status->value),
                ]),
        ]);
    }

    public function store(CreateScheduledCommitRequest $request, #[CurrentUser] User $user, CreateScheduledCommit $action): RedirectResponse
    {
        $action->handle($user, $request->string('body')->value(), CarbonImmutable::parse($request->string('scheduled_at')->value())->utc(), $request->images(), $request->imageDescriptions(), $request->poll(), $request->linkPreview());

        Inertia::flash('scheduled', true);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Commit scheduled. Only you can see it until it is submitted.']);

        return back();
    }

    public function destroy(DeleteScheduledCommitRequest $request, #[CurrentUser] User $user, ScheduledCommit $scheduledCommit, DeleteScheduledCommit $action): RedirectResponse
    {
        $canceled = $action->handle($user, $scheduledCommit);

        Inertia::flash('toast', ['type' => $canceled ? 'success' : 'info', 'message' => $canceled
            ? 'Scheduled commit canceled.'
            : 'This commit was already submitted or canceled. Refresh to see its status.']);

        return back();
    }
}
