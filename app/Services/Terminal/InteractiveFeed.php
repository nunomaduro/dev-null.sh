<?php

declare(strict_types=1);

namespace App\Services\Terminal;

use App\Models\Commit;
use App\Models\User;
use Illuminate\Support\Collection;
use PhpTui\Term\Terminal as PhpTermTerminal;
use PhpTui\Tui\Display\Display;

final readonly class InteractiveFeed
{
    /**
     * Runs the interactive TUI feed application powered by php-tui.
     *
     * @param  Collection<int, Commit>  $commits
     */
    public static function run(
        Collection $commits,
        string $title = 'everyone',
        ?User $user = null,
        ?Display $display = null,
        ?PhpTermTerminal $terminal = null,
        bool $interactive = true
    ): bool {
        if ($commits->isEmpty()) {
            return false;
        }

        $app = new TuiApplication(
            feedCommits: $commits,
            currentUser: $user,
            title: $title,
        );

        return $app->run($display, $terminal, interactive: $interactive);
    }
}
