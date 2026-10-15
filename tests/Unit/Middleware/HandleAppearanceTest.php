<?php

declare(strict_types=1);

use App\Actions\ResolveOmarchyTheme;
use App\Http\Middleware\HandleAppearance;
use App\Omarchy\OmarchyTheme;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\View;

function appearanceMiddleware(): HandleAppearance
{
    return new HandleAppearance(resolve(ResolveOmarchyTheme::class));
}

function runAppearanceMiddleware(string $cookie): void
{
    $request = Request::create('/', 'GET');
    $request->cookies->set('appearance', $cookie);

    appearanceMiddleware()->handle($request, fn ($req): Response => response('OK'));
}

it('shares appearance cookie value with views', function (): void {
    $request = Request::create('/', 'GET');
    $request->cookies->set('appearance', 'dark');

    $response = appearanceMiddleware()->handle($request, fn ($req): Response => response('OK'));

    expect(View::shared('appearance'))->toBe('dark')
        ->and($response->getContent())->toBe('OK');
});

it('defaults to system when appearance cookie not present', function (): void {
    $request = Request::create('/', 'GET');

    $response = appearanceMiddleware()->handle($request, fn ($req): Response => response('OK'));

    expect(View::shared('appearance'))->toBe('system')
        ->and($response->getContent())->toBe('OK');
});

it('handles light appearance', function (): void {
    runAppearanceMiddleware('light');

    expect(View::shared('appearance'))->toBe('light');
});

it('handles system appearance', function (): void {
    runAppearanceMiddleware('system');

    expect(View::shared('appearance'))->toBe('system');
});

it('shares the resolved omarchy theme', function (): void {
    config([
        'omarchy.enabled' => true,
        'omarchy.path' => fixturePath('omarchy/dark/colors.toml'),
        'omarchy.font_path' => null,
        'omarchy.font' => null,
    ]);

    runAppearanceMiddleware('omarchy');

    expect(View::shared('appearance'))->toBe('omarchy')
        ->and(View::shared('omarchy'))->toBeInstanceOf(OmarchyTheme::class);
});

it('falls back to system when omarchy is unavailable', function (): void {
    config(['omarchy.enabled' => false]);

    runAppearanceMiddleware('omarchy');

    expect(View::shared('appearance'))->toBe('system')
        ->and(View::shared('omarchy'))->toBeNull();
});
