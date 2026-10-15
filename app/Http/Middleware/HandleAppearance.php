<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\ResolveOmarchyTheme;
use App\Omarchy\OmarchyTheme;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

final readonly class HandleAppearance
{
    public function __construct(private ResolveOmarchyTheme $omarchy) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $appearance = $request->cookie('appearance') ?? 'system';
        $theme = $this->omarchy->handle();

        if ($appearance === 'omarchy' && ! $theme instanceof OmarchyTheme) {
            $appearance = 'system';
        }

        View::share('appearance', $appearance);
        View::share('omarchy', $theme);

        return $next($request);
    }
}
