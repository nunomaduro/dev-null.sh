<?php

declare(strict_types=1);

namespace App\Actions;

use App\Dto\EasterEgg;
use App\Enums\CommitStatus;
use App\Models\Commit;
use App\Models\User;
use App\Services\EasterEggs;
use Illuminate\Support\Facades\DB;

final readonly class CreateEasterEggReplies
{
    public function __construct(
        private EasterEggs $easterEggs,
        private CreateReplyNotifications $createReplyNotifications,
    ) {
        //
    }

    /**
     * A bot answers recognized commits once, after approval.
     */
    public function handle(Commit $commit): void
    {
        $easterEgg = $this->easterEggs->find($commit->body);

        if (! $easterEgg instanceof EasterEgg) {
            return;
        }

        DB::transaction(function () use ($commit, $easterEgg): void {
            $commit->refresh();

            if ($commit->status !== CommitStatus::Approved) {
                return;
            }

            $author = $commit->user;

            if (! $author instanceof User) {
                return;
            }

            $bot = $this->bot($easterEgg);

            if (! $bot instanceof User || $author->is($bot)) {
                return;
            }

            if ($commit->replies()->whereBelongsTo($bot)->exists()) {
                return;
            }

            $parent = $commit;

            foreach ($easterEgg->replies as $body) {
                $reply = $bot->commits()->create([
                    'body' => str_replace(':name', $author->name, $body),
                    'parent_id' => $parent->id,
                    'root_id' => $commit->threadRootId(),
                    'status' => CommitStatus::Approved,
                ]);

                $this->createReplyNotifications->handle($reply);

                $parent = $reply;
            }
        });
    }

    /**
     * A real GitHub account with the bot's name keeps it, even when it signs in after the bot was renamed away.
     * Bots never sign in, and @world keeps its original id.
     */
    private function bot(EasterEgg $easterEgg): ?User
    {
        $githubId = $easterEgg->bot === 'world' ? '0' : "bot:{$easterEgg->bot}";
        $bot = User::query()->firstWhere('github_id', $githubId);

        if ($bot instanceof User) {
            return $bot->username === $easterEgg->bot ? $bot : null;
        }

        if (User::query()->whereRaw('LOWER(username) = ?', [mb_strtolower($easterEgg->bot)])->exists()) {
            return null;
        }

        return User::query()->create([
            'github_id' => $githubId,
            'username' => $easterEgg->bot,
            'name' => $easterEgg->bot,
            'bio' => $easterEgg->bio,
            'onboarded_at' => now(),
        ]);
    }
}
