<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CommitStatus;
use App\Models\Commit;
use App\Models\LinkPreview;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * @phpstan-type Image array{url: string, width: int, height: int, type: string, alt: string}
 */
final readonly class PageMetadata
{
    /**
     * Lets search engines show large image previews and full snippets of public pages.
     */
    private const string INDEXABLE = 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1';

    private const string NOT_INDEXABLE = 'noindex, follow';

    private const int TITLE_EXCERPT_LENGTH = 70;

    private const int DESCRIPTION_LENGTH = 200;

    private const int MAX_COMMENTS = 10;

    public function __construct(private Urls $urls)
    {
        //
    }

    /**
     * The link preview and structured data of the landing page.
     *
     * @return array{type: string, title: string, description: string, url: string, robots: string, images: list<Image>, structuredData: array<string, mixed>}
     */
    public function home(): array
    {
        $url = route('home');
        $description = __('A social network for developers. Sign in with GitHub, follow people who write code, and commit plain text to a shared log.');

        return [
            'type' => 'website',
            'title' => __('/dev/null: a social network for developers'),
            'description' => $description,
            'url' => $url,
            'robots' => self::INDEXABLE,
            'images' => [[
                'url' => url('/og.png'),
                'width' => OpenGraphCanvas::WIDTH,
                'height' => OpenGraphCanvas::HEIGHT,
                'type' => 'image/png',
                'alt' => __('/dev/null: a social network for developers'),
            ]],
            'structuredData' => [
                '@context' => 'https://schema.org',
                '@graph' => [
                    [
                        '@type' => 'WebSite',
                        '@id' => "{$url}#website",
                        'url' => $url,
                        'name' => '/dev/null',
                        'alternateName' => 'devnull',
                        'description' => $description,
                        'inLanguage' => str_replace('_', '-', app()->getLocale()),
                        'publisher' => ['@id' => "{$url}#organization"],
                    ],
                    [
                        '@type' => 'Organization',
                        '@id' => "{$url}#organization",
                        'name' => '/dev/null',
                        'url' => $url,
                        'logo' => url('/icon-512.png'),
                        'sameAs' => ['https://github.com/nunomaduro/dev-null.sh'],
                    ],
                ],
            ],
        ];
    }

    /**
     * The link preview and structured data of a commit, from its public preview, stars count, images, and the replies shown under it.
     * The commit's own images are previewed first, with its drawn card as a fallback.
     *
     * @param  array<int, Commit>  $replies
     * @return array{type: string, title: string, description: string, url: string, robots: string, images: list<Image>, publishedAt: string, author: string, feed: array{url: string, title: string}, structuredData: array<string, mixed>}|null
     */
    public function commit(Commit $commit, array $replies = []): ?array
    {
        $user = $commit->user;

        if ($commit->trashed() || $commit->status !== CommitStatus::Approved || ! $user instanceof User) {
            return null;
        }

        $parent = $commit->parent;
        $parentUser = $parent instanceof Commit && ! $parent->trashed() && $parent->status === CommitStatus::Approved
            ? $parent->user
            : null;
        $url = route('commit.show', [$user, $commit]);

        $author = $parentUser instanceof User
            ? "{$user->nameWithHandle()} replying to @{$parentUser->username} on /dev/null"
            : "{$user->nameWithHandle()} on /dev/null";

        $body = Str::squish($this->urls->withShortenedUrls($commit->body));
        $title = $author.': “'.Str::limit($body, self::TITLE_EXCERPT_LENGTH, '…', preserveWords: true).'”';
        $description = Str::limit($body, self::DESCRIPTION_LENGTH, '…', preserveWords: true);

        $images = [
            ...$this->commitImages($commit, $user),
            [
                'url' => route('commit-open-graph-image.show', ['user' => $user, 'commit' => $commit, 'v' => $commit->openGraphImageVersion()]),
                'width' => OpenGraphCanvas::WIDTH,
                'height' => OpenGraphCanvas::HEIGHT,
                'type' => 'image/png',
                'alt' => "{$user->nameWithHandle()}: {$description}",
            ],
        ];

        $comments = collect($replies)
            ->filter(fn (Commit $reply): bool => ! $reply->trashed() && $reply->status === CommitStatus::Approved && $reply->user instanceof User)
            ->take(self::MAX_COMMENTS)
            ->map(fn (Commit $reply): array => $this->comment($reply))
            ->values()
            ->all();

        $sharedContent = $this->sharedContent($commit);

        return [
            'type' => 'article',
            'title' => $title,
            'description' => $description,
            'url' => $url,
            'robots' => self::INDEXABLE,
            'images' => $images,
            'publishedAt' => $commit->created_at->toIso8601String(),
            'author' => route('user.show', $user),
            'feed' => $this->feed($user),
            'structuredData' => [
                '@context' => 'https://schema.org',
                '@type' => 'SocialMediaPosting',
                '@id' => $url,
                'url' => $url,
                'mainEntityOfPage' => $url,
                'headline' => Str::limit($description, 110, '…', preserveWords: true),
                'text' => $commit->body,
                'datePublished' => $commit->created_at->toIso8601String(),
                'author' => $this->person($user),
                'image' => array_column($images, 'url'),
                'commentCount' => $commit->replies_count,
                'interactionStatistic' => [
                    $this->interactionCounter('LikeAction', $commit->stars_count),
                    $this->interactionCounter('CommentAction', $commit->replies_count),
                ],
                ...$parent instanceof Commit && $parentUser instanceof User ? [
                    'isPartOf' => route('commit.show', [$parentUser, $parent]),
                ] : [],
                ...$sharedContent !== null ? ['sharedContent' => $sharedContent] : [],
                ...$comments !== [] ? ['comment' => $comments] : [],
            ],
        ];
    }

    /**
     * The link preview and structured data of a profile, from its public counts.
     *
     * @return array{type: string, title: string, description: string, url: string, robots: string, images: list<Image>, feed: array{url: string, title: string}, username: string, structuredData: array<string, mixed>}
     */
    public function user(User $user): array
    {
        $title = "{$user->nameWithHandle()} on /dev/null";

        return [
            'type' => 'profile',
            'title' => $title,
            'description' => $user->bio ?? __("Read @:username's commits on /dev/null, a social network for developers.", ['username' => $user->username]),
            'url' => route('user.show', $user),
            'robots' => self::INDEXABLE,
            'images' => [$this->profileImage($user, $title)],
            'feed' => $this->feed($user),
            'username' => $user->username,
            'structuredData' => [
                '@context' => 'https://schema.org',
                '@type' => 'ProfilePage',
                'url' => route('user.show', $user),
                'dateCreated' => $user->created_at->toIso8601String(),
                'mainEntity' => [
                    ...$this->person($user),
                    'identifier' => $user->id,
                    ...$user->bio !== null ? ['description' => $user->bio] : [],
                    ...$user->avatar_url !== null ? ['image' => $user->avatar_url] : [],
                    'sameAs' => [
                        "https://github.com/{$user->username}",
                        ...filled($user->website) ? [$user->website] : [],
                    ],
                    'interactionStatistic' => [
                        $this->interactionCounter('FollowAction', $user->followers_count),
                        $this->interactionCounter('WriteAction', $user->commits_count),
                    ],
                ],
            ],
        ];
    }

    /**
     * The link preview of who follows a profile, or who it follows. The lists are thin pages, so they are not indexed, though their links are followed.
     *
     * @param  'followers'|'following'  $type
     * @return array{type: string, title: string, description: string, url: string, robots: string, images: list<Image>, username: string}
     */
    public function connections(User $user, string $type): array
    {
        $title = $type === 'followers'
            ? __('People following :user on /dev/null', ['user' => $user->nameWithHandle()])
            : __('People followed by :user on /dev/null', ['user' => $user->nameWithHandle()]);

        return [
            'type' => 'profile',
            'title' => $title,
            'description' => $type === 'followers'
                ? __('See who follows @:username on /dev/null, a social network for developers.', ['username' => $user->username])
                : __('See who @:username follows on /dev/null, a social network for developers.', ['username' => $user->username]),
            'url' => route($type === 'followers' ? 'user-followers.index' : 'user-following.index', $user),
            'robots' => self::NOT_INDEXABLE,
            'images' => [$this->profileImage($user, $title)],
            'username' => $user->username,
        ];
    }

    /**
     * @return list<Image>
     */
    private function commitImages(Commit $commit, User $user): array
    {
        if (! $commit->relationLoaded('images') || ! $commit->imagesAreVisibleTo(null)) {
            return [];
        }

        $count = $commit->images->count();
        $images = [];

        foreach ($commit->images->values() as $index => $image) {
            $images[] = [
                'url' => $image->url(),
                'width' => $image->width,
                'height' => $image->height,
                'type' => 'image/webp',
                'alt' => $count === 1
                    ? "Image attached to the commit of {$user->nameWithHandle()}"
                    : 'Image '.($index + 1)." of {$count} attached to the commit of {$user->nameWithHandle()}",
            ];
        }

        if ($commit->giphy_url !== null) {
            $images[] = [
                'url' => $commit->giphy_url,
                'width' => (int) $commit->giphy_width,
                'height' => (int) $commit->giphy_height,
                'type' => 'image/gif',
                'alt' => $commit->giphy_title ?? "GIF attached to the commit of {$user->nameWithHandle()}",
            ];
        }

        return $images;
    }

    /**
     * The Atom feed of the user's commits.
     *
     * @return array{url: string, title: string}
     */
    private function feed(User $user): array
    {
        return [
            'url' => route('user-feed.show', $user),
            'title' => "{$user->nameWithHandle()} on /dev/null",
        ];
    }

    /**
     * @return Image
     */
    private function profileImage(User $user, string $alt): array
    {
        return [
            'url' => route('user-open-graph-image.show', ['user' => $user, 'v' => $user->openGraphImageVersion()]),
            'width' => OpenGraphCanvas::WIDTH,
            'height' => OpenGraphCanvas::HEIGHT,
            'type' => 'image/png',
            'alt' => $alt,
        ];
    }

    /**
     * The approved commit it quotes, or else the link it previews.
     *
     * @return array<string, mixed>|null
     */
    private function sharedContent(Commit $commit): ?array
    {
        $quoted = $commit->relationLoaded('quotedCommit') ? $commit->quotedCommit : null;

        if ($quoted instanceof Commit && ! $quoted->trashed() && $quoted->status === CommitStatus::Approved && $quoted->user instanceof User) {
            return [
                '@type' => 'SocialMediaPosting',
                'url' => route('commit.show', [$quoted->user, $quoted]),
                'author' => $this->person($quoted->user),
            ];
        }

        $link = $commit->relationLoaded('linkPreview') ? $commit->linkPreview : null;

        if (! $link instanceof LinkPreview) {
            return null;
        }

        return [
            '@type' => 'WebPage',
            'url' => $link->url,
            ...filled($link->title) ? ['name' => $link->title] : [],
            ...filled($link->description) ? ['description' => $link->description] : [],
            ...filled($link->image_url) ? ['image' => $link->image_url] : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function comment(Commit $reply): array
    {
        assert($reply->user instanceof User);

        return [
            '@type' => 'Comment',
            'url' => route('commit.show', [$reply->user, $reply]),
            'text' => $reply->body,
            'datePublished' => $reply->created_at->toIso8601String(),
            'author' => $this->person($reply->user),
        ];
    }

    /**
     * @return array{'@type': string, name: string, alternateName: string, url: string}
     */
    private function person(User $user): array
    {
        return [
            '@type' => 'Person',
            'name' => $user->name,
            'alternateName' => "@{$user->username}",
            'url' => route('user.show', $user),
        ];
    }

    /**
     * @return array{'@type': string, interactionType: string, userInteractionCount: int}
     */
    private function interactionCounter(string $action, int $count): array
    {
        return [
            '@type' => 'InteractionCounter',
            'interactionType' => "https://schema.org/{$action}",
            'userInteractionCount' => $count,
        ];
    }
}
