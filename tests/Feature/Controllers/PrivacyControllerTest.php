<?php

declare(strict_types=1);

use App\Models\User;

it('renders the privacy policy for guests', function (): void {
    $response = $this->get(route('privacy.show'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('privacy/show'));

    $this->assertGuest();
});

it('renders the privacy policy for signed-in users', function (): void {
    $response = $this->actingAs(User::factory()->create())
        ->get(route('privacy.show'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('privacy/show'));
});

it('describes the privacy policy to search engines and link previews', function (): void {
    $this->get(route('privacy.show'))
        ->assertOk()
        ->assertSee('<title>/dev/null privacy policy</title>', false)
        ->assertSee('<link rel="canonical" href="'.route('privacy.show').'">', false)
        ->assertSee('<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">', false)
        ->assertSee('<meta property="og:url" content="'.route('privacy.show').'">', false)
        ->assertSee('<meta property="og:image" content="'.url('/og.png').'">', false);
});
