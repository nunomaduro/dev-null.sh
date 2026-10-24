<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Commit;
use App\Models\LinkPreview;
use App\Models\Topic;
use App\Queries\CommitBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final readonly class DeleteCommitContent
{
    /**
     * Only a commit whose undo window has closed loses its content, so one restored in time, or deleted again since, keeps it.
     * The row stays as a tombstone with its author, for its replies, quotes and reports, but its body, mentions, embedding,
     * link preview, poll and images are erased for good. So are the previews of links to it, which hold its body as their
     * title and description, and fall off whatever linked to it.
     */
    public function handle(Commit $commit): void
    {
        $imagePaths = DB::transaction(function () use ($commit): array {
            $commit = Commit::withTrashed()->lockForUpdate()->find($commit->id);

            if (! $commit instanceof Commit || ! $commit->trashed() || $commit->isRestorable()) {
                return [];
            }

            $imagePaths = $commit->images()->pluck('path')->all();

            $commit->images()->delete();
            $commit->poll()->delete();

            $topicIds = Topic::query()
                ->whereHas('commits', fn (CommitBuilder $query): CommitBuilder => $query->withTrashed()->whereKey($commit->id))
                ->orderBy('id')->lockForUpdate()->pluck('id');
            $commit->topics()->detach();
            Topic::query()->whereKey($topicIds)
                ->whereDoesntHave('commits', fn (CommitBuilder $query): CommitBuilder => $query->withTrashed())
                ->delete();

            LinkPreview::query()->where('url', 'like', "%/commits/{$commit->id}%")->delete();

            $commit->update(['body' => '', 'mentions' => null, 'embedding' => null, 'topic_decision' => null, 'link_preview_id' => null]);

            return $imagePaths;
        });

        Storage::disk()->delete($imagePaths);
    }
}
