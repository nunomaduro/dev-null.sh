<?php

declare(strict_types=1);

use App\Models\User;

it('renders the terms of service for guests', function (): void {
    $response = $this->get(route('terms.show'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('terms/show'));

    $this->assertGuest();
});

it('renders the terms of service for signed-in users', function (): void {
    $response = $this->actingAs(User::factory()->create())
        ->get(route('terms.show'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('terms/show'));
});

it('describes the terms of service to search engines and link previews', function (): void {
    $this->get(route('terms.show'))
        ->assertOk()
        ->assertSee('<title>/dev/null terms of service</title>', false)
        ->assertSee('<link rel="canonical" href="'.route('terms.show').'">', false)
        ->assertSee('<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">', false)
        ->assertSee('<meta property="og:url" content="'.route('terms.show').'">', false)
        ->assertSee('<meta property="og:image" content="'.url('/og.png').'">', false);
});
