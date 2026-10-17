<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateCommitTip;
use App\Http\Requests\CreateCommitTipRequest;
use App\Models\Commit;
use App\Models\CommitTip;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final readonly class CommitTipController
{
    public function store(
        CreateCommitTipRequest $request,
        #[CurrentUser] User $user,
        Commit $commit,
        CreateCommitTip $action,
    ): RedirectResponse {
        $tip = $action->handle($user, $commit, $request->gift(), $request->message());

        if (! $tip instanceof CommitTip) {
            return back()->withErrors(['gift' => __('Not enough Bytes to send this gift.')]);
        }

        return back();
    }
}
