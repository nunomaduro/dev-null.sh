<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CommitImpression;
use App\Models\CommitImpressionBatch;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('commits:prune-impressions')]
#[Description('Delete impression batch receipts and viewer cooldowns older than 24 hours')]
final class PruneCommitImpressionsCommand extends Command
{
    public function handle(): int
    {
        $cutoff = now()->subDay();

        CommitImpressionBatch::query()->where('created_at', '<', $cutoff)->delete();
        CommitImpression::query()->where('last_counted_at', '<', $cutoff)->delete();

        return self::SUCCESS;
    }
}
