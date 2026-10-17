<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\CommitStatus;
use App\Models\Commit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

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
     * @return EloquentCollection<int, User>
     */
    public function usersOn(int $page): EloquentCollection
    {
        return $this->users()
            ->select(['id', 'username', 'updated_at'])
            ->orderBy('id')
            ->forPage($page, self::PER_PAGE)
            ->get();
    }

    /**
     * Sitemaps need only a few scalar columns. Avoid hydrating 10,000 Eloquent
     * models and their user relations on every request.
     *
     * @return Collection<int, stdClass>
     */
    public function commitsOn(int $page): Collection
    {
        return DB::table('commits')
            ->join('users', 'users.id', '=', 'commits.user_id')
            ->where('commits.status', CommitStatus::Approved->value)
            ->whereNull('commits.deleted_at')
            ->orderBy('commits.id')
            ->forPage($page, self::PER_PAGE)
            ->get(['commits.id', 'commits.updated_at', 'users.username']);
    }

    /**
     * Fetch all images for the page with one query rather than one per commit.
     *
     * @param array<int, string> $commitIds
     * @return Collection<int, stdClass>
     */
    public function imagesFor(array $commitIds): Collection
    {
        return DB::table('commit_images')
            ->whereIn('commit_id', $commitIds)
            ->orderBy('commit_id')
            ->orderBy('position')
            ->get(['id', 'commit_id']);
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
