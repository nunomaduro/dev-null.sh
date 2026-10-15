<?php

declare(strict_types=1);

it('applies omarchy tokens and font for the omarchy appearance', function (): void {
    config([
        'omarchy.enabled' => true,
        'omarchy.path' => fixturePath('omarchy/dark/colors.toml'),
        'omarchy.font_path' => fixturePath('fontconfig/fonts.conf'),
        'omarchy.font' => null,
    ]);

    $this->withUnencryptedCookie('appearance', 'omarchy')
        ->get(route('home'))
        ->assertOk()
        ->assertSee('data-omarchy-mode="dark"', false)
        ->assertSee('--background: #0b0b0a;', false)
        ->assertSee('--primary: #b6aa8b;', false)
        ->assertSee('--font-mono: "JetBrainsMono Nerd Font"', false);
});

it('does not apply omarchy tokens for other appearances', function (): void {
    config([
        'omarchy.enabled' => true,
        'omarchy.path' => fixturePath('omarchy/dark/colors.toml'),
        'omarchy.font_path' => null,
        'omarchy.font' => null,
    ]);

    $this->withUnencryptedCookie('appearance', 'dark')
        ->get(route('home'))
        ->assertOk()
        ->assertDontSee('--background: #0b0b0a;', false);
});

it('falls back to system when omarchy is requested but unavailable', function (): void {
    config(['omarchy.enabled' => false]);

    $this->withUnencryptedCookie('appearance', 'omarchy')
        ->get(route('home'))
        ->assertOk()
        ->assertSee('data-omarchy-mode=""', false)
        ->assertSee('name="theme-color" content="#f6f7f8"', false)
        ->assertDontSee('--primary-soft:', false);
});
