<?php

declare(strict_types=1);

namespace App\Dto;

use Illuminate\Support\Arr;
use Laravel\Socialite\Two\User as GitHubUser;

/**
 * The part of a GitHub account that is mirrored onto a user, on sign-in and on sync.
 */
final readonly class GitHubProfile
{
    private function __construct(
        public string $username,
        public string $name,
        public ?string $avatarUrl,
        public ?string $bio,
        public ?string $location,
        public ?string $website,
        public ?string $company,
    ) {
        //
    }

    /**
     * Maps a user object of the GitHub REST API, such as `GET /user/{id}`.
     *
     * @param  array<array-key, mixed>  $account
     */
    public static function fromAccount(array $account): self
    {
        $username = Arr::get($account, 'login');
        assert(is_string($username));

        return new self(
            username: $username,
            name: self::nullIfBlank(Arr::get($account, 'name')) ?? $username,
            avatarUrl: self::nullIfBlank(Arr::get($account, 'avatar_url')),
            bio: self::nullIfBlank(Arr::get($account, 'bio')),
            location: self::nullIfBlank(Arr::get($account, 'location')),
            website: self::website(Arr::get($account, 'blog')),
            company: self::nullIfBlank(Arr::get($account, 'company')),
        );
    }

    public static function fromSocialite(GitHubUser $githubUser): self
    {
        return self::fromAccount([
            ...$githubUser->getRaw(),
            'login' => (string) $githubUser->getNickname(),
            'name' => $githubUser->getName(),
            'avatar_url' => $githubUser->getAvatar(),
        ]);
    }

    /**
     * @return array{username: string, name: string, avatar_url: string|null, bio: string|null, location: string|null, website: string|null, company: string|null}
     */
    public function attributes(): array
    {
        return [
            'username' => $this->username,
            'name' => $this->name,
            'avatar_url' => $this->avatarUrl,
            'bio' => $this->bio,
            'location' => $this->location,
            'website' => $this->website,
            'company' => $this->company,
        ];
    }

    private static function website(mixed $value): ?string
    {
        $website = self::nullIfBlank($value);

        if ($website === null || preg_match('/^https?:\/\//i', $website) === 1) {
            return $website;
        }

        return 'https://'.$website;
    }

    private static function nullIfBlank(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = mb_trim($value);

        return $value === '' ? null : $value;
    }
}
