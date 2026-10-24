<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Commit;
use App\Queries\CommitBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Support\Facades\Bus;

#[Queue('low')]
final class BackfillCommitTopics implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $batchSize, public readonly string $throughId, public readonly ?string $afterId = null)
    {
        //
    }

    public function handle(): void
    {
        $commits = Commit::query()->approved()->whereNull('parent_id')->where('body', '!=', '')
            ->whereNull('topic_decision')
            ->where('id', '<=', $this->throughId)
            ->when($this->afterId, fn (CommitBuilder $query): CommitBuilder => $query->where('id', '>', $this->afterId))
            ->orderBy('id')->limit($this->batchSize)->get(['id']);

        $last = $commits->last();

        if (! $last instanceof Commit) {
            return;
        }

        Bus::chain([
            new ClassifyCommitTopics(array_values($commits->map(fn (Commit $commit): string => $commit->id)->all())),
            new self($this->batchSize, $this->throughId, $last->id),
        ])->onQueue('low')->dispatch();
    }
}
