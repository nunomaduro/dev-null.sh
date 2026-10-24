<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ConversationKind;
use App\Models\Commit;
use App\Models\CommitImage;
use App\Models\CommitReport;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageImage;
use App\Models\MessageVoice;
use App\Models\ScheduledCommit;
use App\Models\Topic;
use App\Models\User;
use App\Queries\CommitBuilder;
use App\Services\CommitTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final readonly class DeleteUser
{
    public function __construct(private UpdateCommitHotScores $updateCommitHotScores, private DeleteOrgs $deleteOrgs)
    {
        //
    }

    /**
     * The user's top-level commits are erased with every reply under them, and so are their replies, except the ones
     * other people replied to: those stay behind as tombstones with no author or body, so the replies under them keep their thread.
     * The database cascades the image rows of erased commits and the user's scheduled commits, and the voice notes of their
     * messages, but the files are erased here. Orgs the user was the last one in go too, with everything sent in them,
     * as they do when the last member leaves.
     */
    public function handle(User $user): void
    {
        $imagePaths = DB::transaction(function () use ($user): array {
            $this->updateCommitHotScores->handle($this->engagedCommitIds($user));

            $imagePaths = [...$this->imagePaths($user), ...$this->scheduledImagePaths($user), ...$this->messageImagePaths($user), ...$this->messageVoicePaths($user)];

            $translationKeys = Commit::query()->withTrashed()
                ->where(fn (CommitBuilder $query): CommitBuilder => $query->where('user_id', $user->id)
                    ->orWhereIn('root_id', $user->commits()->withTrashed()->topLevel()->select('id')))
                ->select(['id', 'body'])
                ->lazyById(500)
                ->map(resolve(CommitTranslations::class)->key(...))->unique()->all();

            $commitIds = Commit::query()->withTrashed()
                ->where(fn (CommitBuilder $query): CommitBuilder => $query->where('user_id', $user->id)
                    ->orWhereIn('root_id', $user->commits()->withTrashed()->topLevel()->select('id')))
                ->select('id');
            (clone $commitIds)->orderBy('id')->lockForUpdate()->get();
            $topicIds = Topic::query()
                ->whereHas('commits', fn (CommitBuilder $query): CommitBuilder => $query->withTrashed()->whereIn('commits.id', $commitIds))
                ->orderBy('id')->lockForUpdate()->pluck('id');
            DB::table('commit_topic')->whereIn('commit_id', $commitIds)->delete();
            Topic::query()->whereKey($topicIds)
                ->whereDoesntHave('commits', fn (CommitBuilder $query): CommitBuilder => $query->withTrashed())
                ->delete();

            $user->commits()->withTrashed()->topLevel()->forceDelete();
            CommitImage::query()->whereIn('commit_id', $this->replies($user)->select('id'))->delete();
            CommitReport::query()->whereIn('commit_id', $this->replies($user)->select('id'))->delete();

            while ($this->replies($user)->whereDoesntHave('replies', fn (CommitBuilder $replies): CommitBuilder => $replies->withTrashed())->forceDelete() > 0) {
                //
            }

            $user->commits()->delete();
            $user->commits()->withTrashed()->update(['user_id' => null, 'body' => '', 'mentions' => null, 'embedding' => null, 'topic_decision' => null, 'link_preview_id' => null]);

            $this->deleteOrgs->handle($this->emptyOrgs($user));

            $user->delete();

            foreach ($translationKeys as $key) {
                Cache::forget($key);
            }

            return $imagePaths;
        });

        Storage::disk()->delete($imagePaths);
    }

    /**
     * The commits the user starred, and their replies and quotes, whose threads rank lower once the user's stars,
     * replies and quotes are gone.
     *
     * @return array<int, string>
     */
    private function engagedCommitIds(User $user): array
    {
        return [
            ...$user->starredCommits()->get(['commits.id'])->map(fn (Commit $commit): string => $commit->id)->all(),
            ...$user->commits()
                ->withTrashed()
                ->where(fn (CommitBuilder $query): CommitBuilder => $query->whereNotNull('root_id')->orWhereNotNull('quoted_commit_id'))
                ->get(['id'])
                ->map(fn (Commit $commit): string => $commit->id)
                ->all(),
        ];
    }

    /**
     * @return HasMany<Commit, User>
     */
    private function replies(User $user): HasMany
    {
        return $user->commits()->withTrashed()->whereNotNull('parent_id');
    }

    /**
     * The images of the user's commits and of every reply in the threads they started, which go along with them.
     *
     * @return array<int, string>
     */
    private function imagePaths(User $user): array
    {
        return CommitImage::query()
            ->whereIn('commit_id', $user->commits()->withTrashed()->select('id'))
            ->orWhereIn('commit_id', Commit::query()->withTrashed()->whereIn('root_id', $user->commits()->withTrashed()->topLevel()->select('id'))->select('id'))
            ->get(['path'])
            ->map(fn (CommitImage $image): string => $image->path)
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function scheduledImagePaths(User $user): array
    {
        return ScheduledCommit::query()
            ->where('user_id', $user->id)
            ->whereNotNull('images')
            ->get(['images'])
            ->flatMap(fn (ScheduledCommit $scheduled): array => array_column($scheduled->images ?? [], 'path'))
            ->all();
    }

    /**
     * The images the user sent in remotes, whose messages go along with them.
     *
     * @return array<int, string>
     */
    private function messageImagePaths(User $user): array
    {
        return MessageImage::query()
            ->whereIn('message_id', Message::query()->where('user_id', $user->id)->select('id'))
            ->get(['path'])
            ->map(fn (MessageImage $image): string => $image->path)
            ->all();
    }

    /**
     * The voice notes the user sent in remotes, whose messages go along with them.
     *
     * @return array<int, string>
     */
    private function messageVoicePaths(User $user): array
    {
        return MessageVoice::query()
            ->whereIn('message_id', Message::query()->where('user_id', $user->id)->select('id'))
            ->get(['path'])
            ->map(fn (MessageVoice $voice): string => $voice->path)
            ->all();
    }

    /**
     * @return Builder<Conversation>
     */
    private function emptyOrgs(User $user): Builder
    {
        return Conversation::query()
            ->where('kind', ConversationKind::Org)
            ->whereHas('participants', fn (Builder $query): Builder => $query->where('user_id', $user->id))
            ->whereDoesntHave('participants', fn (Builder $query): Builder => $query->where('user_id', '!=', $user->id));
    }
}
