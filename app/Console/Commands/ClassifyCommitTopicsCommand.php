<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\BackfillCommitTopics;
use App\Models\Commit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('commits:classify-topics {--batch-size=40 : Commits per queued batch (1–100)}')]
#[Description('Backfill root commit topics in sequential low priority batches')]
final class ClassifyCommitTopicsCommand extends Command
{
    public function handle(): int
    {
        $batchSize = filter_var($this->option('batch-size'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);

        if ($batchSize === false) {
            $this->components->error('The batch size must be an integer between 1 and 100.');

            return self::INVALID;
        }

        $last = Commit::query()->latest('id')->first(['id']);

        if (! $last instanceof Commit) {
            $this->components->info('No commits to classify.');

            return self::SUCCESS;
        }

        dispatch(new BackfillCommitTopics($batchSize, $last->id));

        $this->components->info("Queued topic backfill in batches of {$batchSize}.");

        return self::SUCCESS;
    }
}
