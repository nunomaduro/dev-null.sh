<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Commit;
use App\Models\CommitImage;
use App\Models\CommitReport;
use App\Models\User;
use App\Queries\CommitBuilder;
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
     * The database cascades the image rows of erased commits, but the image files are erased here.
     */
    public function handle(User $user): void
    {
        $imagePaths = DB::transaction(function () use ($user): array {
            $this->updateCommitHotScores->handle($this->engagedCommitIds($user));

            // Erase portable signatures before anonymizing replies into tombstones.
            // The proofs contain the author's exact signed message and public key.
            DB::table('commit_signatures')
                ->whereIn('commit_id', $user->commits()->withTrashed()->select('id'))
                ->delete();
            $user->commits()->withTrashed()->update(['signer_fingerprint' => null]);

            $topLevelIds = $user->commits()->withTrashed()->topLevel()->pluck('id')->all();
            $replyIds = $user->commits()->withTrashed()->whereNotNull('parent_id')->pluck('id')->all();

            $imagePaths = $this->imagePaths([...$this->withReplies($topLevelIds), ...$replyIds]);

            Commit::query()->withTrashed()->whereKey($topLevelIds)->forceDelete();
            CommitImage::query()->whereIn('commit_id', $replyIds)->delete();
            CommitReport::query()->whereIn('commit_id', $replyIds)->delete();

            while (($leafIds = $this->repliesWithoutReplies($user)) !== []) {
                Commit::query()->withTrashed()->whereKey($leafIds)->forceDelete();
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
     * @param  array<mixed>  $commitIds
     * @return array<mixed>
     */
    private function withReplies(array $commitIds): array
    {
        $allIds = [];
        $level = $commitIds;

        while ($level !== []) {
            $allIds = [...$allIds, ...$level];
            $level = Commit::query()->withTrashed()->whereIn('parent_id', $level)->pluck('id')->all();
        }

        return $allIds;
    }

    /**
     * @return array<mixed>
     */
    private function repliesWithoutReplies(User $user): array
    {
        return $user->commits()
            ->withTrashed()
            ->whereDoesntHave('replies', fn (CommitBuilder $replies): CommitBuilder => $replies->withTrashed())
            ->pluck('id')
            ->all();
    }

    /**
     * @param  array<mixed>  $commitIds
     * @return array<int, string>
     */
    private function imagePaths(array $commitIds): array
    {
        return CommitImage::query()
            ->whereIn('commit_id', $commitIds)
            ->get(['path'])
            ->map(fn (CommitImage $image): string => $image->path)
            ->all();
    }
}
