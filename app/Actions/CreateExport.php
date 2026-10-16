<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\NotificationType;
use App\Models\Bookmark;
use App\Models\CommitImage;
use App\Models\DirectMessage;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\User;
use App\Queries\CommitBuilder;
use Generator;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

final readonly class CreateExport
{
    /**
     * Everything stored about the user, deleted commits included, but never secrets like the remember token or session ids.
     *
     * @return array{schema_version: int, exported_at: string, profile: array<string, bool|int|string|null>, commits: Generator<int, array<string, mixed>>, polls: Generator<int, array{commit_id: string, duration_minutes: int, opened_at: string|null, options: list<string>, created_at: string}>, poll_votes: Generator<int, array{commit_id: string, option: string, created_at: string}>, stars: Generator<int, string>, bookmarks: Generator<int, string>, following: Generator<int, array{username: string}>, followers: Generator<int, array{username: string}>, muting: Generator<int, array{username: string}>, blocking: Generator<int, array{username: string}>, notifications: Generator<int, array<string, string|null>>, direct_messages: Generator<int, array<string, mixed>>, ssh_keys: array<mixed>, sessions: Collection<int, stdClass>}
     */
    public function handle(User $user): array
    {
        return [
            'schema_version' => 3,
            'exported_at' => now()->toIso8601String(),
            'profile' => [
                'dm_policy' => $user->dm_policy,
                'dm_read_receipts' => $user->dm_read_receipts,
                'github_id' => $user->github_id,
                'username' => $user->username,
                'name' => $user->name,
                'email' => $user->email,
                'avatar_url' => $user->avatar_url,
                'bio' => $user->bio,
                'location' => $user->location,
                'website' => $user->website,
                'company' => $user->company,
                'has_sponsors_listing' => $user->has_sponsors_listing,
                'preferred_lane' => $user->preferred_lane,
                'pinned_commit_id' => $user->pinned_commit_id,
                'created_at' => $user->created_at->toIso8601String(),
                'updated_at' => $user->updated_at->toIso8601String(),
            ],
            'commits' => $this->commits($user),
            'polls' => $this->polls($user),
            'poll_votes' => $this->pollVotes($user),
            'stars' => $this->stars($user),
            'bookmarks' => $this->bookmarks($user),
            'following' => $this->usernames($user->following()),
            'followers' => $this->usernames($user->followers()),
            'muting' => $this->usernames($user->muting()),
            'blocking' => $this->usernames($user->blocking()),
            'notifications' => $this->notifications($user),
            'direct_messages' => $this->directMessages($user),
            'ssh_keys' => $user->sshKeys()->pluck('fingerprint')->all(),
            'sessions' => $this->sessions($user),
        ];
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function commits(User $user): Generator
    {
        foreach ($user->commits()->withTrashed()->with('images')->lazyById(500) as $commit) {
            yield [
                'id' => $commit->id,
                'parent_id' => $commit->parent_id,
                'quoted_commit_id' => $commit->quoted_commit_id,
                'series_id' => $commit->series_id,
                'series_position' => $commit->series_position,
                'series_size' => $commit->series_size,
                'body' => $commit->body,
                'status' => $commit->status->value,
                'images' => $commit->images->map(fn (CommitImage $image): string => $image->url())->all(),
                'created_at' => $commit->created_at->toIso8601String(),
                'updated_at' => $commit->updated_at->toIso8601String(),
                'deleted_at' => $commit->deleted_at?->toIso8601String(),
            ];
        }
    }

    /**
     * @return Generator<int, array{commit_id: string, duration_minutes: int, opened_at: string|null, options: list<string>, created_at: string}>
     */
    private function polls(User $user): Generator
    {
        $polls = Poll::query()
            ->whereHas('commit', fn (CommitBuilder $query): CommitBuilder => $query->where('user_id', $user->id))
            ->with('options')
            ->lazyById(500);

        foreach ($polls as $poll) {
            yield [
                'commit_id' => $poll->commit_id,
                'duration_minutes' => $poll->duration->value,
                'opened_at' => $poll->opened_at?->toIso8601String(),
                'options' => array_values($poll->options->map(fn (PollOption $option): string => $option->label)->all()),
                'created_at' => $poll->created_at->toIso8601String(),
            ];
        }
    }

    /**
     * Only the user's own choices: never who else voted.
     *
     * @return Generator<int, array{commit_id: string, option: string, created_at: string}>
     */
    private function pollVotes(User $user): Generator
    {
        foreach ($user->pollVotes()->with(['poll', 'option'])->lazyById(500) as $vote) {
            yield [
                'commit_id' => $vote->poll->commit_id,
                'option' => $vote->option->label,
                'created_at' => $vote->created_at->toIso8601String(),
            ];
        }
    }

    /**
     * @return Generator<int, string>
     */
    private function stars(User $user): Generator
    {
        foreach ($user->starredCommits()->withTrashed()->select('commits.id')->lazyById(500, 'commits.id', 'id') as $commit) {
            yield $commit->id;
        }
    }

    /**
     * @return Generator<int, string>
     */
    private function bookmarks(User $user): Generator
    {
        foreach (Bookmark::query()->where('user_id', $user->id)->lazyById(500) as $bookmark) {
            yield $bookmark->commit_id;
        }
    }

    /**
     * @param  BelongsToMany<User, User>  $users
     * @return Generator<int, array{username: string}>
     */
    private function usernames(BelongsToMany $users): Generator
    {
        foreach ($users->select(['users.id', 'users.username'])->lazyById(500, 'users.id', 'id') as $user) {
            yield ['username' => $user->username];
        }
    }

    /**
     * Moderators stay anonymous, as they are on the notifications page.
     *
     * @return Generator<int, array<string, string|null>>
     */
    private function notifications(User $user): Generator
    {
        foreach ($user->notifications()->with('actor')->lazyById(500) as $notification) {
            $isModeration = in_array($notification->type, [NotificationType::Approved, NotificationType::Rejected], true);

            yield [
                'type' => $notification->type->value,
                'actor' => $isModeration ? null : $notification->actor->username,
                'commit_id' => $notification->commit_id,
                'conversation_id' => $notification->conversation_id,
                'reason' => $notification->reason,
                'read_at' => $notification->read_at?->toIso8601String(),
                'created_at' => $notification->created_at->toIso8601String(),
            ];
        }
    }

    /** @return Generator<int, array<string, mixed>> */
    private function directMessages(User $user): Generator
    {
        foreach (DirectMessage::query()->where('sender_id', $user->id)->lazyById(500) as $message) {
            yield ['id' => $message->id, 'conversation_id' => $message->conversation_id, 'body' => $message->body, 'created_at' => $message->created_at->toIso8601String()];
        }
    }

    /**
     * Never the session id or payload: either could be used to take over the session.
     *
     * @return Collection<int, stdClass>
     */
    private function sessions(User $user): Collection
    {
        return DB::table('sessions')
            ->where('user_id', $user->id)
            ->get(['ip_address', 'user_agent', 'last_activity']);
    }
}
