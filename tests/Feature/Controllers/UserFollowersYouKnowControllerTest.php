<?php

declare(strict_types=1);

use App\Models\User;

it('lists the followers of a user whom the viewer follows', function (): void {
    $viewer = User::factory()->create();
    $user = User::factory()->create(['username' => 'octocat']);
    $known = User::factory()->create();
    $stranger = User::factory()->create();
    $user->followers()->attach([$known->id, $stranger->id]);
    $viewer->following()->attach($known);

    $this->actingAs($viewer)
        ->get(route('user-followers-you-know.index', $user))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('user/connections')
            ->where('type', 'followers_you_know')
            ->where('user.username', 'octocat')
            ->has('users.data', 1)
            ->where('users.data.0.id', $known->id)
            ->where('users.data.0.is_followed', true));
});

it('lists the newest follow of the user first', function (): void {
    $viewer = User::factory()->create();
    $user = User::factory()->create();
    [$earlier, $later] = User::factory()->count(2)->create();
    $user->followers()->attach($earlier, ['created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
    $user->followers()->attach($later);
    $viewer->following()->attach([$earlier->id, $later->id]);

    $this->actingAs($viewer)
        ->get(route('user-followers-you-know.index', $user))
        ->assertInertia(fn ($page) => $page
            ->where('users.data.0.id', $later->id)
            ->where('users.data.1.id', $earlier->id));
});

it('sends guests home', function (): void {
    $this->get(route('user-followers-you-know.index', User::factory()->create()))
        ->assertRedirect(route('home'));
});

it('does not list them across a block', function (): void {
    $viewer = User::factory()->create();
    $user = User::factory()->create();
    $user->blocking()->attach($viewer);

    $this->actingAs($viewer)
        ->get(route('user-followers-you-know.index', $user))
        ->assertNotFound();
});
