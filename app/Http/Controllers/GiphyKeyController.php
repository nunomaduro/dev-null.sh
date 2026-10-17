<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateGiphyKeyRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

final readonly class GiphyKeyController
{
    public function update(UpdateGiphyKeyRequest $request, #[CurrentUser] User $user): RedirectResponse
    {
        $user->update(['giphy_key' => $request->string('key')->toString()]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'GIPHY key connected.',
        ]);

        return back();
    }

    public function destroy(#[CurrentUser] User $user): RedirectResponse
    {
        $user->update(['giphy_key' => null]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'GIPHY key removed.',
        ]);

        return back();
    }
}
