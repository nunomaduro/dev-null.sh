<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\CommitStatus;
use App\Models\Commit;
use App\Models\User;
use App\Services\HelloWorld;
use Illuminate\Support\Facades\DB;

final readonly class CreateWorldReply
{
    public const string GITHUB_ID = '0';

    private const string USERNAME = 'world';

    public function __construct(
        private HelloWorld $helloWorld,
        private CreateReplyNotifications $createReplyNotifications,
    ) {
        //
    }

    /**
     * @world answers an approved hello world, once. A pending one waits until it is approved.
     */
    public function handle(Commit $commit): void
    {
        if (! $this->helloWorld->matches($commit->body)) {
            return;
        }

        DB::transaction(function () use ($commit): void {
            $commit->refresh();

            if ($commit->status !== CommitStatus::Approved) {
                return;
            }

            $author = $commit->user;

            if (! $author instanceof User) {
                return;
            }

            $world = $this->world();

            if (! $world instanceof User || $author->is($world)) {
                return;
            }

            if ($commit->replies()->whereBelongsTo($world)->exists()) {
                return;
            }

            $reply = $world->commits()->create([
                'body' => "Hello, {$author->name}.",
                'parent_id' => $commit->id,
                'status' => CommitStatus::Approved,
            ]);

            $this->createReplyNotifications->handle($reply);
        });
    }

    /**
     * A real GitHub account named world keeps the name, even when it signs in after the bot took it and renames the bot
     * away. The bot never signs in.
     */
    private function world(): ?User
    {
        $world = User::query()->firstWhere('github_id', self::GITHUB_ID);

        if ($world instanceof User) {
            return $world->username === self::USERNAME ? $world : null;
        }

        if (User::query()->where('username', self::USERNAME)->exists()) {
            return null;
        }

        return User::query()->create([
            'github_id' => self::GITHUB_ID,
            'username' => self::USERNAME,
            'name' => self::USERNAME,
            'bio' => 'I answer to hello world.',
            'onboarded_at' => now(),
        ]);
    }
}
