<?php

declare(strict_types=1);

use App\Actions\ResolveOmarchyTheme;

beforeEach(function (): void {
    config([
        'omarchy.enabled' => true,
        'omarchy.path' => fixturePath('omarchy/dark/colors.toml'),
        'omarchy.font_path' => null,
        'omarchy.font' => null,
    ]);
});

it('resolves the active omarchy theme', function (): void {
    $theme = resolve(ResolveOmarchyTheme::class)->handle();

    expect($theme)->not->toBeNull()
        ->and($theme?->name)->toBe('Warm Glass')
        ->and($theme?->mode)->toBe('dark')
        ->and($theme?->font)->toBeNull()
        ->and($theme?->tokens['--background'])->toBe('#0b0b0a')
        ->and($theme?->tokens['--foreground'])->toBe('#e5e3da')
        ->and($theme?->tokens['--card'])->toBe('#1c1c19')
        ->and($theme?->tokens['--popover'])->toBe('#1c1c19')
        ->and($theme?->tokens['--primary'])->toBe('#b6aa8b')
        ->and($theme?->tokens['--primary-foreground'])->toBe('#0b0b0a')
        ->and($theme?->tokens['--primary-hover'])->toBe('color-mix(in oklab, var(--primary) 82%, #ffffff)')
        ->and($theme?->tokens['--primary-soft'])->toBe('#514b3f')
        ->and($theme?->tokens['--muted-foreground'])->toBe('#929087')
        ->and($theme?->tokens['--destructive'])->toBe('#d99188')
        ->and($theme?->tokens['--destructive-foreground'])->toBe('#edada3')
        ->and($theme?->tokens['--border'])->toBe('#45443d')
        ->and($theme?->tokens['--input'])->toBe('#514b3f')
        ->and($theme?->tokens['--ring'])->toBe('#b6aa8b')
        ->and($theme?->tokens['--rail'])->toBe('#514b3f')
        ->and($theme?->tokens['--lane-0'])->toBe('#edada3')
        ->and($theme?->tokens['--lane-5'])->toBe('#c1d0df');
});

it('resolves a light omarchy theme with fallbacks', function (): void {
    config(['omarchy.path' => fixturePath('omarchy/light/colors.toml')]);

    $theme = resolve(ResolveOmarchyTheme::class)->handle();

    expect($theme?->name)->toBe('Solar Light')
        ->and($theme?->mode)->toBe('light')
        ->and($theme?->tokens['--primary-hover'])->toBe('color-mix(in oklab, var(--primary) 88%, #000000)')
        ->and($theme?->tokens['--card'])->toBe('#f6f7f8')
        ->and($theme?->tokens['--muted-foreground'])->toBe('#121417')
        ->and($theme?->tokens['--destructive'])->toBe('#121417')
        ->and($theme?->tokens['--border'])->toBe('#f6f7f8')
        ->and($theme?->tokens['--lane-3'])->toBe('#121417');
});

it('falls back to a default name and ignores malformed lines', function (): void {
    config(['omarchy.path' => fixturePath('omarchy/anonymous/colors.toml')]);

    $theme = resolve(ResolveOmarchyTheme::class)->handle();

    expect($theme?->name)->toBe('Omarchy')
        ->and($theme?->mode)->toBe('dark')
        ->and($theme?->tokens['--background'])->toBe('#101014')
        ->and($theme?->tokens['--foreground'])->toBe('#f0f0f0');
});

it('resolves a theme from a glob path', function (): void {
    config(['omarchy.path' => fixturePath('omarchy/dark/colo*rs.toml')]);

    expect(resolve(ResolveOmarchyTheme::class)->handle()?->name)->toBe('Warm Glass');
});

it('uses the configured font', function (): void {
    config(['omarchy.font' => 'Iosevka Nerd Font']);

    expect(resolve(ResolveOmarchyTheme::class)->handle()?->font)->toBe('Iosevka Nerd Font');
});

it('detects the font from fontconfig', function (): void {
    config(['omarchy.font_path' => fixturePath('fontconfig/fonts.conf')]);

    expect(resolve(ResolveOmarchyTheme::class)->handle()?->font)->toBe('JetBrainsMono Nerd Font');
});

it('ignores the generic monospace alias', function (): void {
    config(['omarchy.font_path' => fixturePath('fontconfig/only-monospace.conf')]);

    expect(resolve(ResolveOmarchyTheme::class)->handle()?->font)->toBeNull();
});

it('returns no font when fontconfig has no families', function (): void {
    config(['omarchy.font_path' => fixturePath('fontconfig/no-strings.conf')]);

    expect(resolve(ResolveOmarchyTheme::class)->handle()?->font)->toBeNull();
});

it('returns null when disabled', function (): void {
    config(['omarchy.enabled' => false]);

    expect(resolve(ResolveOmarchyTheme::class)->handle())->toBeNull();
});

it('returns null when no path is configured', function (): void {
    config(['omarchy.path' => '']);

    expect(resolve(ResolveOmarchyTheme::class)->handle())->toBeNull();
});

it('returns null when the theme file is missing', function (): void {
    config(['omarchy.path' => fixturePath('omarchy/missing/colors.toml')]);

    expect(resolve(ResolveOmarchyTheme::class)->handle())->toBeNull();
});

it('returns null when the glob matches nothing', function (): void {
    config(['omarchy.path' => fixturePath('omarchy/*/nope.toml')]);

    expect(resolve(ResolveOmarchyTheme::class)->handle())->toBeNull();
});

it('returns null when the theme has no colors', function (): void {
    config(['omarchy.path' => fixturePath('omarchy/incomplete/colors.toml')]);

    expect(resolve(ResolveOmarchyTheme::class)->handle())->toBeNull();
});
