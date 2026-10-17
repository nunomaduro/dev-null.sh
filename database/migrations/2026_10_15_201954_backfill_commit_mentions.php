<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('commits')->whereNull('mentions')->select(['id', 'body'])
            ->chunkById(200, function (Collection $commits): void {
                /** @var Collection<int, object{id: string, body: string}> $commits */
                $usernamesByCommit = $commits->mapWithKeys(fn (object $commit): array => [
                    $commit->id => $this->usernames($commit->body),
                ]);

                /** @var Collection<int, object{id: string, username: string}> $users */
                $users = DB::table('users')
                    ->whereIn(DB::raw('LOWER(username)'), $usernamesByCommit->flatten()->unique()->all())
                    ->get(['id', 'username']);

                $identities = $users->mapWithKeys(fn (object $user): array => [mb_strtolower($user->username) => $user->id]);

                foreach ($usernamesByCommit as $id => $usernames) {
                    DB::table('commits')->where('id', $id)->whereNull('mentions')->update([
                        'mentions' => json_encode((object) $identities->only($usernames)->all(), JSON_THROW_ON_ERROR),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Keep captured identities until the column itself is rolled back.
    }

    /**
     * Freeze the parser here so future application changes cannot change this backfill.
     *
     * @return list<string>
     */
    private function usernames(string $body): array
    {
        $prose = (string) preg_replace('/((?<![^\n])`{3,}[^`\r\n]*\r?\n(?:[\s\S]*?\r?\n)?`{3,}[ \t]*(?![^\r\n])|`[^`\r\n]+`)/', "\n", $body);

        preg_match_all('/(?<![\p{L}\p{N}_.\/@-])@([A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38})(?![A-Za-z0-9_@\/-]|\.[A-Za-z0-9])/u', $prose, $matches);

        return array_values(array_unique(array_map(strtolower(...), $matches[1])));
    }
};
