<?php

declare(strict_types=1);

namespace App\Services\Terminal;

use App\Models\Commit;
use App\Models\User;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

final readonly class TerminalRenderer
{
    /**
     * RGB colors for the 6 customizable user lanes (matching OpenGraphCanvas LANES).
     *
     * @var array<int, array{0: int, 1: int, 2: int}>
     */
    public const array LANES = [
        0 => [217, 72, 15],   // Orange (#d9480f)
        1 => [47, 158, 68],   // Green (#2f9e44)
        2 => [156, 54, 181],  // Purple (#9c36b5)
        3 => [12, 133, 153],  // Cyan (#0c8599)
        4 => [194, 37, 92],   // Pink (#c2255c)
        5 => [183, 121, 31],  // Yellow (#b7791f)
    ];

    /**
     * Standard 16-color ANSI fallbacks for terminal clients without TrueColor support.
     *
     * @var array<int, string>
     */
    public const array LANES_16 = [
        0 => "\033[33m", // Yellow / Orange
        1 => "\033[32m", // Green
        2 => "\033[35m", // Magenta
        3 => "\033[36m", // Cyan
        4 => "\033[31m", // Red / Pink
        5 => "\033[33m", // Yellow
    ];

    /**
     * Sanitizes user-provided text for terminal output by stripping ANSI escapes and C0 controls.
     */
    public static function sanitize(string $text): string
    {
        // Strip ANSI escape sequences: \033[ ... m or other CSI sequences
        $stripped = (string) preg_replace('/\033\[[0-9;]*[a-zA-Z]/', '', $text);

        // Strip C0 control characters except \n, \r, \t
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $stripped);
    }

    /**
     * Renders the ASCII art banner.
     */
    public function banner(bool $color = true): string
    {
        $bold = $color ? "\033[1m" : '';
        $dim = $color ? "\033[90m" : '';
        $reset = $color ? "\033[0m" : '';

        return implode("\n", [
            "  {$bold}   _                     _ _           _      {$reset}",
            "  {$bold}__| | _____   __  _ __  | | |  ___ ___| |__   {$reset}",
            "  {$bold}/ _` |/ _ \ \ / / | '_ \ | | | / __/ __| '_ \  {$reset}",
            "  {$bold}| (_| |  __/\ V /  | | | || | |_\__ \__ \ | | | {$reset}",
            "  {$bold}\__,_|\___| \_/   |_| |_| \__(_)___/___/_| |_| {$reset}",
            '',
            "  {$dim}A social network for developers. Pipe your thoughts to /dev/null.{$reset}",
            '',
        ]);
    }

    /**
     * Renders a timeline log (e.g. following or everyone) as an ASCII git DAG.
     *
     * @param  iterable<int, Commit>  $commits
     */
    public function log(iterable $commits, string $title = 'everyone', bool $color = true, bool $force16 = false): string
    {
        $dim = $color ? "\033[90m" : '';
        $bold = $color ? "\033[1m" : '';
        $reset = $color ? "\033[0m" : '';

        $output = [
            $this->banner($color),
            "  {$bold}Log:{$reset} {$title}",
            "  {$dim}".str_repeat('─', 60)."{$reset}",
            '',
        ];

        $commitsList = is_array($commits) ? $commits : iterator_to_array($commits);
        $total = count($commitsList);

        if ($total === 0) {
            $output[] = "  {$dim}Nobody has committed anything yet. Be the first.{$reset}";
            $output[] = '';
        } else {
            foreach ($commitsList as $index => $commit) {
                $isLast = $index === $total - 1;
                $output[] = $this->commitCard($commit, $color, $force16, ! $isLast);
            }
        }

        $output[] = $this->footer($color);

        return implode("\n", $output)."\n";
    }

    /**
     * Renders a user's terminal profile card and recent commits.
     *
     * @param  iterable<int, Commit>  $commits
     */
    public function profile(User $user, iterable $commits, bool $color = true, bool $force16 = false): string
    {
        $lane = $this->laneColor($user->lane(), $color, $force16);
        $bold = $color ? "\033[1m" : '';
        $dim = $color ? "\033[90m" : '';
        $reset = $color ? "\033[0m" : '';

        $name = $user->name ? ' ('.self::sanitize($user->name).')' : '';
        $username = self::sanitize($user->username);
        $bio = $user->bio ? "\n  {$dim}".self::sanitize($user->bio)."{$reset}" : '';

        $user->loadPublicCounts();

        $stats = "{$user->commits_count} commits · {$user->followers_count} followers";

        $header = [
            $this->banner($color),
            "  {$lane}@{$username}{$reset}{$bold}{$name}{$reset}",
            "  {$dim}{$stats}{$reset}{$bio}",
            "  {$dim}".str_repeat('─', 60)."{$reset}",
            '',
        ];

        $commitsList = is_array($commits) ? $commits : iterator_to_array($commits);
        $total = count($commitsList);

        if ($total === 0) {
            $header[] = "  {$dim}@{$username} has not committed anything yet.{$reset}";
            $header[] = '';
        } else {
            foreach ($commitsList as $index => $commit) {
                $isLast = $index === $total - 1;
                $header[] = $this->commitCard($commit, $color, $force16, ! $isLast);
            }
        }

        $header[] = $this->footer($color);

        return implode("\n", $header)."\n";
    }

    /**
     * Renders a single commit with its ancestors and replies thread.
     *
     * @param  iterable<int, Commit>  $ancestors
     * @param  iterable<int, Commit>  $replies
     */
    public function thread(Commit $commit, iterable $ancestors, iterable $replies, bool $color = true, bool $force16 = false): string
    {
        $output = [$this->banner($color)];

        $ancestorList = is_array($ancestors) ? $ancestors : iterator_to_array($ancestors);
        $repliesList = is_array($replies) ? $replies : iterator_to_array($replies);

        // Ancestors...
        foreach ($ancestorList as $ancestor) {
            $output[] = $this->commitCard($ancestor, $color, $force16, true);
        }

        // Focused commit...
        $output[] = $this->commitCard($commit, $color, $force16, count($repliesList) > 0, isFocused: true);

        // Replies...
        $totalReplies = count($repliesList);
        foreach ($repliesList as $index => $reply) {
            $isLast = $index === $totalReplies - 1;
            $output[] = $this->commitCard($reply, $color, $force16, ! $isLast, isReply: true);
        }

        $output[] = $this->footer($color);

        return implode("\n", $output)."\n";
    }

    /**
     * Renders an individual commit node with ASCII rail.
     */
    public function commitCard(Commit $commit, bool $color = true, bool $force16 = false, bool $hasFollowing = true, bool $isFocused = false, bool $isReply = false): string
    {
        $lane = $this->laneColor($commit->user?->lane() ?? 0, $color, $force16);
        $bold = $color ? "\033[1m" : '';
        $dim = $color ? "\033[90m" : '';
        $reset = $color ? "\033[0m" : '';

        $hash = Str::substr($commit->id, 0, 7);
        $time = $commit->created_at->diffForHumans(short: true);
        $stars = $commit->stars_count > 0 ? " · {$commit->stars_count} ★" : '';
        $replies = $commit->replies_count > 0 ? " · {$commit->replies_count} ⤿" : '';

        $railStem = $hasFollowing ? "{$dim}│{$reset}" : ' ';
        $nodeSymbol = $isReply ? '├─' : '*';

        if ($isFocused) {
            $hashText = "{$bold}{$hash}{$reset} {$dim}(HEAD){$reset}";
        } else {
            $hashText = "{$bold}{$hash}{$reset}";
        }

        $username = self::sanitize($commit->user->username ?? 'ghost');
        $headerLine = "  {$lane}{$nodeSymbol}{$reset}   {$hashText} {$lane}@{$username}{$reset} {$dim}· {$time}{$stars}{$replies}{$reset}";

        $body = self::sanitize($commit->body);
        $bodyLines = explode("\n", wordwrap($body, 70, "\n", true));
        $renderedBody = [];

        foreach ($bodyLines as $line) {
            $renderedBody[] = "  {$railStem}   {$line}";
        }

        if ($hasFollowing) {
            $renderedBody[] = "  {$railStem}";
        } else {
            $renderedBody[] = '';
        }

        return $headerLine."\n".implode("\n", $renderedBody);
    }

    /**
     * Renders the terminal quick-command footer.
     */
    public function footer(bool $color = true): string
    {
        $dim = $color ? "\033[90m" : '';
        $reset = $color ? "\033[0m" : '';
        $baseUrl = url('/');

        return implode("\n", [
            "  {$dim}".str_repeat('─', 60)."{$reset}",
            "  {$dim}Commit:{$reset} curl -fsSL {$baseUrl}/commit | bash -s -- 'Your message'",
            "  {$dim}Log:   {$reset} php artisan log",
        ]);
    }

    /**
     * Resolves the ANSI escape sequence for a user lane (24-bit TrueColor or 16-color ANSI).
     */
    public function laneColor(int $laneIndex, bool $color, bool $force16 = false): string
    {
        if (! $color) {
            return '';
        }

        if ($force16) {
            return self::LANES_16[$laneIndex] ?? self::LANES_16[0];
        }

        $rgb = self::LANES[$laneIndex] ?? self::LANES[0];

        return sprintf("\033[38;2;%d;%d;%dm", $rgb[0], $rgb[1], $rgb[2]);
    }

    /**
     * Determines whether colors should be enabled based on standard environment conventions.
     */
    public function supportsColor(): bool
    {
        if (isset($_SERVER['NO_COLOR']) && Request::server('NO_COLOR') !== '') {
            return false;
        }

        return true;
    }
}
