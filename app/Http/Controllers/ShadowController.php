<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\UpdateShadow;
use App\Http\Requests\UpdateShadowRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;

final readonly class ShadowController
{
    public function update(
        UpdateShadowRequest $request,
        #[CurrentUser] User $user,
        UpdateShadow $action,
    ): RedirectResponse {
        try {
            $action->handle(
                $user,
                $request->shadowName(),
                $request->glyph(),
                $request->skin(),
            );
        } catch (InvalidArgumentException) {
            return back()->withErrors(['skin' => __('Unlock this skin before equipping it.')]);
        }

        return back();
    }
}
