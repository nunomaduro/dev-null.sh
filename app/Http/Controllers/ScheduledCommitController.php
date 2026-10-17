<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateScheduledCommit;
use App\Actions\DeleteScheduledCommit;
use App\Actions\UpdateScheduledCommitScheduledAt;
use App\Http\Requests\CreateScheduledCommitRequest;
use App\Http\Requests\DeleteScheduledCommitRequest;
use App\Http\Requests\UpdateScheduledCommitRequest;
use App\Models\ScheduledCommit;
use App\Models\User;
use App\Services\CommitBodies;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final readonly class ScheduledCommitController
{
    public function index(#[CurrentUser] User $user, CommitBodies $bodies): Response
    {
        return Inertia::render('scheduled-commits/index', [
            'scheduledCommits' => fn (): LengthAwarePaginator => ScheduledCommit::query()->where('user_id', $user->id)
                ->whereNull('canceled_at')->with('commit')
                ->orderByRaw('submitted_at IS NOT NULL')->orderBy('scheduled_at')->orderBy('id')
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
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Commit scheduled. Only you can see it until it is submitted.')]);

        return back();
    }

    public function update(UpdateScheduledCommitRequest $request, #[CurrentUser] User $user, ScheduledCommit $scheduledCommit, UpdateScheduledCommitScheduledAt $action): RedirectResponse
    {
        $updated = $action->handle($user, $scheduledCommit, CarbonImmutable::parse($request->string('scheduled_at')->value())->utc());

        Inertia::flash('toast', ['type' => $updated ? 'success' : 'info', 'message' => $updated
            ? __('Commit rescheduled.')
            : __('This commit was already submitted or canceled. Refresh to see its status.')]);

        return back();
    }

    public function destroy(DeleteScheduledCommitRequest $request, #[CurrentUser] User $user, ScheduledCommit $scheduledCommit, DeleteScheduledCommit $action): RedirectResponse
    {
        $canceled = $action->handle($user, $scheduledCommit);

        Inertia::flash('toast', ['type' => $canceled ? 'success' : 'info', 'message' => $canceled
            ? __('Scheduled commit canceled.')
            : __('This commit was already submitted or canceled. Refresh to see its status.')]);

        return back();
    }
}
