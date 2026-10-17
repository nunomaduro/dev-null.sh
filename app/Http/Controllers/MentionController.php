<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;

final readonly class MentionController
{
    public function show(User $user): RedirectResponse
    {
        return to_route('user.show', ['user' => $user]);
    }
}
