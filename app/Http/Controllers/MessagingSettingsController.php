<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\UpdateMessagingSettings;
use App\Http\Requests\UpdateMessagingSettingsRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final readonly class MessagingSettingsController
{
    public function edit(#[CurrentUser] User $user): Response
    {
        return Inertia::render('messages/settings', ['policy' => $user->dm_policy, 'readReceipts' => $user->dm_read_receipts]);
    }

    public function update(UpdateMessagingSettingsRequest $request, #[CurrentUser] User $user, UpdateMessagingSettings $action): RedirectResponse
    {
        $action->handle($user, $request->string('dm_policy')->value(), $request->boolean('dm_read_receipts'));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Messaging settings saved.')]);

        return back();
    }
}
