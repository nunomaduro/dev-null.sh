<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\UpdateCommitTopics;
use App\Exceptions\CommitProcessingFailed;
use App\Models\Commit;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Throwable;

#[Backoff([60, 600])]
#[FailOnTimeout]
#[MaxExceptions(3)]
#[Queue('low')]
#[Timeout(900)]
final class ClassifyCommitTopics implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $commitIds
     */
    public function __construct(public readonly array $commitIds)
    {
        //
    }

    public function retryUntil(): CarbonInterface
    {
        return now()->addDay();
    }

    public function handle(UpdateCommitTopics $action): void
    {
        try {
            $action->handle(Commit::query()->whereKey($this->commitIds)->orderBy('id')->get(['id', 'body'])
                ->mapWithKeys(fn (Commit $commit): array => [$commit->id => $commit->body])->all());
        } catch (LockTimeoutException) {
            $this->release(60);
        } catch (Throwable $throwable) {
            throw new CommitProcessingFailed('Topic classification', implode(', ', $this->commitIds), $throwable);
        }
    }
}
