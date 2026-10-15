<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\ResolveOmarchyTheme;
use App\Models\User;
use App\Omarchy\OmarchyTheme;
use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    /**
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    public function __construct(private readonly ResolveOmarchyTheme $omarchy) {}

    /**
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $theme = $this->omarchy->handle();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => fn (): ?User => $request->user()?->loadCounts(),
                'isAdmin' => $request->user()?->isAdmin() ?? false,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'omarchy' => $theme instanceof OmarchyTheme ? [
                'name' => $theme->name,
                'mode' => $theme->mode,
                'font' => $theme->font,
            ] : null,
        ];
    }
}
