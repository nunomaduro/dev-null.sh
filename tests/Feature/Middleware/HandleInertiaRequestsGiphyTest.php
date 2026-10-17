<?php

declare(strict_types=1);

use App\Enums\Log;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('shares the giphy key of the signed-in user', function (): void {
    $user = User::factory()->withGiphyKey('giphy-test')->create();

    $this->actingAs($user)
        ->get(route('log.show', Log::Following))
        ->assertInertia(fn (Assert $page): Assert => $page->where('giphy.key', 'giphy-test'));
});

it('shares no giphy config without a key', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('log.show', Log::Following))
        ->assertInertia(fn (Assert $page): Assert => $page->where('giphy', null));
});

it('shares no giphy config for guests', function (): void {
    $this->get(route('log.show', Log::Trending))
        ->assertInertia(fn (Assert $page): Assert => $page->where('giphy', null));
});
