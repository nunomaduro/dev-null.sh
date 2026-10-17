<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Commit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final readonly class SitemapQuery
{
    /**
     * Well under the 50,000 URLs a sitemap may list, so every page stays quick to build.
     */
    public const int PER_PAGE = 10_000;

    public function userPages(): int
    {
        return (int) ceil($this->users()->count() / self::PER_PAGE);
    }

    public function commitPages(): int
    {
        return (int) ceil($this->commits()->count() / self::PER_PAGE);
    }

    /**
     * Read as plain rows rather than models, since a page lists thousands of them.
     *
     * @return list<array{username: string, updated_at: CarbonImmutable}>
     */
    public function usersOn(int $page): array
    {
        /** @var list<object{username: string, updated_at: string}> $users */
        $users = $this->users()
            ->orderBy('id')
            ->forPage($page, self::PER_PAGE)
            ->toBase()
            ->get(['username', 'updated_at'])
            ->all();

        return array_map(fn (object $user): array => [
            'username' => $user->username,
            'updated_at' => CarbonImmutable::parse($user->updated_at),
        ], $users);
    }

    /**
     * Read as plain rows rather than models, with the author and the images in the same query, since a page lists
     * thousands of them.
     *
     * @return list<array{id: string, username: string, updated_at: CarbonImmutable, image_ids: list<string>}>
     */
    public function commitsOn(int $page): array
    {
        $onPage = $this->commits()
            ->select(['id', 'user_id', 'updated_at'])
            ->orderBy('id')
            ->forPage($page, self::PER_PAGE);

        /** @var list<object{id: string, username: string, updated_at: string, image_id: string|null}> $rows */
        $rows = DB::query()
            ->fromSub($onPage, 'commits')
            ->join('users', 'users.id', '=', 'commits.user_id')
            ->leftJoin('commit_images', 'commit_images.commit_id', '=', 'commits.id')
            ->orderBy('commits.id')
            ->orderBy('commit_images.position')
            ->get(['commits.id', 'users.username', 'commits.updated_at', 'commit_images.id as image_id'])
            ->all();

        $commits = [];

        foreach ($rows as $row) {
            $commits[$row->id] ??= [
                'id' => $row->id,
                'username' => $row->username,
                'updated_at' => CarbonImmutable::parse($row->updated_at),
                'image_ids' => [],
            ];

            if ($row->image_id !== null) {
                $commits[$row->id]['image_ids'][] = $row->image_id;
            }
        }

        return array_values($commits);
    }

    /**
     * Profiles without a single approved commit have nothing to read yet.
     *
     * @return Builder<User>
     */
    private function users(): Builder
    {
        return User::query()->whereHas('commits', fn (CommitBuilder $query): CommitBuilder => $query->approved());
    }

    private function commits(): CommitBuilder
    {
        return Commit::query()->approved();
    }
}
