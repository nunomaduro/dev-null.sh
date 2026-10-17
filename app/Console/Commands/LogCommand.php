<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\CreateCommit;
use App\Enums\CommitStatus;
use App\Models\Commit;
use App\Models\User;
use App\Queries\CommitByReferenceQuery;
use App\Services\Terminal\InteractiveFeed;
use App\Services\Terminal\TerminalRenderer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature("log {user? : View a specific developer's log} {--as= : Act as a specific developer handle} {--everyone : View the everyone log instead of your following feed} {--limit=25 : Number of commits to display} {--post= : Directly commit a message from the CLI} {--reply= : Reply to a specific commit hash when using --post} {--tui : Force interactive TUI mode} {--no-tui : Disable interactive TUI mode}")]
#[Description('Read the devnull log from your terminal with interactive TUI or git DAG view')]
final class LogCommand extends Command
{
    public function handle(TerminalRenderer $renderer): int
    {
        // Handle direct non-interactive CLI posting...
        $postMessage = $this->option('post');

        if (is_string($postMessage) && $postMessage !== '') {
            return $this->handleDirectPost($postMessage);
        }

        $limit = max(1, min(100, (int) $this->option('limit')));
        $username = $this->argument('user');

        $user = null;
        $title = 'everyone';

        if (is_string($username) && $username !== '') {
            $cleanUsername = mb_ltrim($username, '@');
            $user = User::query()->where('username', $cleanUsername)->first();

            if ($user === null) {
                $this->components->error("User @{$cleanUsername} not found.");

                return self::FAILURE;
            }

            $title = "@{$user->username}";
            $commits = $user->commits()
                ->approved()
                ->topLevel()
                ->withPublicPreview()
                ->latest()
                ->limit($limit)
                ->get();
        } else {
            $commits = Commit::query()
                ->approved()
                ->topLevel()
                ->withPublicPreview()
                ->latest()
                ->limit($limit)
                ->get();
        }

        if ($commits->isEmpty()) {
            $this->components->info('No commits found in the log yet.');

            return self::SUCCESS;
        }

        // Identify acting developer...
        $asUsername = $this->option('as');
        $activeUser = null;

        if (is_string($asUsername) && $asUsername !== '') {
            $activeUser = User::query()->where('username', mb_ltrim($asUsername, '@'))->first();
        }

        $isTty = (bool) $this->option('tui')
            || (function_exists('stream_isatty') && stream_isatty(STDIN) && stream_isatty(STDOUT));
        $disableTui = (bool) $this->option('no-tui');

        if (! $disableTui && $isTty) {
            if (InteractiveFeed::run($commits, $title, $activeUser, interactive: ! app()->runningUnitTests())) {
                return self::SUCCESS;
            }
        }

        $color = $renderer->supportsColor();

        $dag = $user !== null
            ? $renderer->profile($user, $commits, color: $color)
            : $renderer->log($commits, title: $title, color: $color);

        foreach (explode("\n", mb_rtrim($dag)) as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }

    /**
     * Handles non-interactive direct commit posting from CLI flags.
     */
    private function handleDirectPost(string $message): int
    {
        $asUsername = $this->option('as') ?? $this->argument('user');

        $user = (is_string($asUsername) && $asUsername !== '')
            ? User::query()->where('username', mb_ltrim($asUsername, '@'))->first()
            : User::query()->first();

        if ($user === null) {
            $this->components->error('Cannot commit: no user found. Provide --as=@username.');

            return self::FAILURE;
        }

        $replyTarget = $this->option('reply');
        $parent = null;

        if (is_string($replyTarget) && $replyTarget !== '') {
            $parent = resolve(CommitByReferenceQuery::class)->get($user, $replyTarget)?->first();

            if ($parent === null) {
                $this->components->error("Reply target commit {$replyTarget} not found.");

                return self::FAILURE;
            }
        }

        $created = resolve(CreateCommit::class)->handle($user, $message, $parent);
        $hash = Str::substr($created->id, 0, 7);
        $status = $created->status === CommitStatus::Approved ? 'Approved' : 'Pending review';

        $this->components->info("Committed: [{$hash}] by @{$user->username} ({$status})");

        return self::SUCCESS;
    }
}
