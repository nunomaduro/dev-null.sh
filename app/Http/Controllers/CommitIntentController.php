<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

final readonly class CommitIntentController
{
    /**
     * Shares a link to /dev/null, landing on the log with the text added to the composer's draft, never committed.
     */
    public function show(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $text = $request->string('text')->trim()->value();

        if ($text !== '') {
            Inertia::flash('intent', $text);
        }

        return to_route('log.show', $user->log);
    }
}
