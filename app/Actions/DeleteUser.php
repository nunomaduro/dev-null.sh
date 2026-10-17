<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Commit;
use App\Models\CommitImage;
use App\Models\CommitReport;
use App\Models\ScheduledCommit;
use App\Models\User;
use App\Queries\CommitBuilder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final readonly class DeleteUser
{
    public function __construct(private UpdateCommitHotScores $updateCommitHotScores)
    {
        //
    }

    /**
     * The user's top-level commits are erased with every reply under them, and so are their replies, except the ones
     * other people replied to: those stay behind as tombstones with no author, so the replies under them keep their thread.
     * The database cascades the image rows of erased commits and the user's scheduled commits, but the image files are erased here.
     */
    public function handle(User $user): void
    {
        $imagePaths = DB::transaction(function () use ($user): array {
            $this->updateCommitHotScores->handle($this->engagedCommitIds($user));

            $imagePaths = [...$this->imagePaths($user), ...$this->scheduledImagePaths($user)];

            $user->commits()->withTrashed()->topLevel()->forceDelete();
            CommitImage::query()->whereIn('commit_id', $this->replies($user)->select('id'))->delete();
            CommitReport::query()->whereIn('commit_id', $this->replies($user)->select('id'))->delete();

            while ($this->replies($user)->whereDoesntHave('replies', fn (CommitBuilder $replies): CommitBuilder => $replies->withTrashed())->forceDelete() > 0) {
                //
            }

            $user->commits()->delete();
            $user->commits()->withTrashed()->update(['user_id' => null]);

            $user->delete();

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
}
