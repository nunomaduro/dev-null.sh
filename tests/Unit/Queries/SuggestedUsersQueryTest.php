<?php

declare(strict_types=1);

use App\Models\Commit;
use App\Models\User;
use App\Queries\SuggestedUsersQuery;
use Illuminate\Database\Eloquent\Collection;

/**
 * @return Collection<int, User>
 */
function vouchedFor(User $user, int $count): Collection
{
    $friend = User::factory()->create();
    $user->following()->attach($friend);

    $vouched = User::factory()->count($count)->has(Commit::factory())->create();
    $friend->following()->attach($vouched);

    return $vouched;
}

/**
 * @return Collection<int, User>
 */
function popularUsers(int $count): Collection
{
    return User::factory()->count($count)->has(Commit::factory())->create()
        ->each(fn (User $popular) => $popular->followers()->attach(User::factory()->count(2)->create()));
}

it('suggests people with commits the user does not follow yet', function (): void {
    $user = User::factory()->has(Commit::factory())->create();
    $followed = User::factory()->has(Commit::factory())->create();
    $stranger = User::factory()->has(Commit::factory())->create();

    $user->following()->attach($followed);

    $suggestions = resolve(SuggestedUsersQuery::class)->get($user);

    expect($suggestions->pluck('id')->all())->toBe([$stranger->id]);
});

it('skips people without approved commits', function (): void {
    $user = User::factory()->create();

    User::factory()->create();
    User::factory()->has(Commit::factory()->pending())->create();
    User::factory()->has(Commit::factory()->rejected())->create();

    expect(resolve(SuggestedUsersQuery::class)->get($user))->toBeEmpty();
});

it('skips people who only replied', function (): void {
    $user = User::factory()->create();

    $parent = Commit::factory()->for($user)->create();
    User::factory()->has(Commit::factory()->for($parent, 'parent'))->create();

    expect(resolve(SuggestedUsersQuery::class)->get($user))->toBeEmpty();
});

it('picks the people most followed by the user follows', function (): void {
    $user = User::factory()->create();
    [$friendA, $friendB] = User::factory()->count(2)->create();

    $user->following()->attach([$friendA->id, $friendB->id]);

    $followedByOneFriend = User::factory()->has(Commit::factory())->create();
    $followedByBothFriends = User::factory()->has(Commit::factory())->create();

    $followedByOneFriend->followers()->attach($friendA);
    $followedByBothFriends->followers()->attach([$friendA->id, $friendB->id]);

    $suggestions = resolve(SuggestedUsersQuery::class)->get($user, limit: 1);

    expect($suggestions->pluck('id')->all())->toBe([$followedByBothFriends->id])
        ->and($suggestions->first()->followed_by_following_count)->toBe(2);
});

it('mixes one or two popular people with people the user might know', function (): void {
    $user = User::factory()->create();
    $network = vouchedFor($user, 5);
    $popular = popularUsers(5);

    $suggestions = resolve(SuggestedUsersQuery::class)->get($user);
    $popularCount = $suggestions->filter(fn (User $suggested): bool => $popular->contains($suggested))->count();

    expect($suggestions)->toHaveCount(3)
        ->and($popularCount)->toBeIn([1, 2])
        ->and($suggestions->filter(fn (User $suggested): bool => $network->contains($suggested)))->toHaveCount(3 - $popularCount);
});

it('suggests only popular people when the user might know nobody', function (): void {
    $user = User::factory()->create();
    $popular = popularUsers(4);

    $suggestions = resolve(SuggestedUsersQuery::class)->get($user);

    expect($suggestions)->toHaveCount(3)
        ->and($suggestions->pluck('id')->all())->each->toBeIn($popular->modelKeys());
});

it('suggests only people the user might know when nobody else is around', function (): void {
    $user = User::factory()->create();
    $network = vouchedFor($user, 4);

    $suggestions = resolve(SuggestedUsersQuery::class)->get($user);

    expect($suggestions)->toHaveCount(3)
        ->and($suggestions->pluck('id')->all())->each->toBeIn($network->modelKeys());
});

it('picks popular people from the most followed', function (): void {
    $user = User::factory()->create();

    $mostFollowed = User::factory()->count(20)->has(Commit::factory())->create()
        ->each(fn (User $popular) => $popular->followers()->attach(User::factory()->create()));
    $unknown = User::factory()->has(Commit::factory())->create();

    $suggestions = resolve(SuggestedUsersQuery::class)->get($user);

    expect($suggestions)->toHaveCount(3)
        ->and($suggestions->pluck('id')->all())->not->toContain($unknown->id)
        ->each->toBeIn($mostFollowed->modelKeys());
});

it('picks the 20 most followed among people who have committed', function (): void {
    $user = User::factory()->create();

    $mostFollowed = User::factory()->count(20)->has(Commit::factory())->create()
        ->each(fn (User $popular) => $popular->followers()->attach(User::factory()->create()));
    User::factory()->has(Commit::factory()->pending())->create()->followers()->attach(User::factory()->count(2)->create());

    $query = resolve(SuggestedUsersQuery::class);
    $suggested = collect(range(0, 6))->flatMap(fn (int $round): array => $query->get($user, round: $round)->pluck('id')->all());

    expect($suggested->unique()->sort()->values()->all())->toBe(collect($mostFollowed->modelKeys())->sort()->values()->all());
});

it('keeps the same suggestions in the same order during the day', function (): void {
    $user = User::factory()->create();
    vouchedFor($user, 3);
    popularUsers(5);

    $query = resolve(SuggestedUsersQuery::class);

    expect($query->get($user)->pluck('id')->all())->toBe($query->get($user)->pluck('id')->all());
});

it('limits the number of suggestions', function (): void {
    $user = User::factory()->create();

    User::factory()->count(5)->has(Commit::factory())->create();

    expect(resolve(SuggestedUsersQuery::class)->get($user))->toHaveCount(3)
        ->and(resolve(SuggestedUsersQuery::class)->get($user, limit: 2))->toHaveCount(2);
});

it('does not suggest muted people or people across a block', function (): void {
    $user = User::factory()->create();
    $muted = User::factory()->has(Commit::factory())->create();
    $blocked = User::factory()->has(Commit::factory())->create();
    $blocker = User::factory()->has(Commit::factory())->create();
    $stranger = User::factory()->has(Commit::factory())->create();

    $user->muting()->attach($muted);
    $user->blocking()->attach($blocked);
    $blocker->blocking()->attach($user);

    $friend = User::factory()->create();
    $user->following()->attach($friend);
    $friend->following()->attach([$muted->id, $blocked->id, $blocker->id]);

    expect(resolve(SuggestedUsersQuery::class)->get($user)->pluck('id')->all())->toBe([$stranger->id]);
});

it('suggests other people on the next round', function (): void {
    $user = User::factory()->create();
    vouchedFor($user, 3);
    popularUsers(3);

    $query = resolve(SuggestedUsersQuery::class);

    $first = $query->get($user)->pluck('id')->all();
    $second = $query->get($user, round: 1)->pluck('id')->all();

    expect($first)->toHaveCount(3)
        ->and($second)->toHaveCount(3)
        ->and(array_intersect($first, $second))->toBeEmpty();
});

it('keeps the first round as the suggestions of the day', function (): void {
    $user = User::factory()->create();
    vouchedFor($user, 4);
    popularUsers(5);

    $query = resolve(SuggestedUsersQuery::class);

    expect($query->get($user, round: 0)->pluck('id')->all())->toBe($query->get($user)->pluck('id')->all());
});

it('goes back to the first suggestions after the last round', function (): void {
    $user = User::factory()->create();
    popularUsers(6);

    $query = resolve(SuggestedUsersQuery::class);

    expect($query->get($user, round: 2)->pluck('id')->all())->toBe($query->get($user)->pluck('id')->all())
        ->and($query->get($user, round: 3)->pluck('id')->all())->toBe($query->get($user, round: 1)->pluck('id')->all());
});

it('tops up the last round so it never shows fewer people', function (): void {
    $user = User::factory()->create();
    $popular = popularUsers(4);

    $query = resolve(SuggestedUsersQuery::class);

    $first = $query->get($user)->pluck('id')->all();
    $last = $query->get($user, round: 1)->pluck('id')->all();

    expect($last)->toHaveCount(3)
        ->and($last)->each->toBeIn($popular->modelKeys())
        ->and(array_diff($popular->modelKeys(), $first))->each->toBeIn($last);
});

it('keeps the same suggestions when there is nobody else to suggest', function (): void {
    $user = User::factory()->create();
    popularUsers(2);

    $query = resolve(SuggestedUsersQuery::class);

    expect($query->get($user, round: 1)->pluck('id')->all())->toBe($query->get($user)->pluck('id')->all());
});
