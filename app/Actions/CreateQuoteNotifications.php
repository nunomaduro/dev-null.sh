<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\CommitStatus;
use App\Enums\NotificationType;
use App\Models\Commit;
use App\Models\Notification;
use App\Queries\SilencedActorQuery;
use Illuminate\Support\Str;

final readonly class CreateQuoteNotifications
{
    /**
     * Notifies the author of each quoted commit, but only once the quote is approved, so
     * nobody hears about a quote they cannot see yet. Nobody hears from people they mute or
     * from across a block either. Safe to call more than once.
     */
    public function handle(Commit ...$commits): void
    {
        $quoteIds = array_map(
            fn (Commit $commit): string => $commit->id,
            array_filter($commits, fn (Commit $commit): bool => $commit->quoted_commit_id !== null),
        );

        if ($quoteIds === []) {
            return;
        }

        $silenced = new SilencedActorQuery('quoted.user_id', 'commits.user_id');

        $quotes = Commit::query()
            ->select('commits.*')
            ->join('commits as quoted', 'quoted.id', '=', 'commits.quoted_commit_id')
            ->whereKey($quoteIds)
            ->where('commits.status', CommitStatus::Approved)
            ->whereNotExists($silenced->mutes(...))
            ->whereNotExists($silenced->blocking(...))
            ->whereNotExists($silenced->blockers(...))
            ->with('quotedCommit')
            ->get();

        $notifications = [];

        foreach ($quotes as $quote) {
            $quoted = $quote->quotedCommit;

            if (! $quoted instanceof Commit || $quoted->user_id === $quote->user_id) {
                continue;
            }

            $notifications[] = [
                'id' => Str::uuid7()->toString(),
                'recipient_id' => $quoted->user_id,
                'actor_id' => $quote->user_id,
                'type' => NotificationType::Quoted->value,
                'commit_id' => $quote->id,
                'key' => 'quoted:'.$quote->id,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        Notification::query()->insertOrIgnore($notifications);
    }
}
