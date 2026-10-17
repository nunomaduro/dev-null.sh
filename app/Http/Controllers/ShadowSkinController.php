<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\UpdateShadowSkin;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final readonly class ShadowSkinController
{
    public function store(
        #[CurrentUser] User $user,
        string $skin,
        UpdateShadowSkin $action,
    ): RedirectResponse {
        $shadow = $action->handle($user, $skin);

        if (! in_array($skin, $shadow->unlocked_skins, true)) {
            return back()->withErrors(['skin' => __('Unable to unlock skin. Check your Byte balance.')]);
        }

        return back();
    }
}
