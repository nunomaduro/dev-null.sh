<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateCommitImpressions;
use App\Http\Requests\CreateCommitImpressionsRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Response;

final readonly class CommitImpressionController
{
    public function store(CreateCommitImpressionsRequest $request, #[CurrentUser] User $user, CreateCommitImpressions $action): Response
    {
        $action->handle($user, $request->batchId(), $request->impressions());

        return response()->noContent();
    }
}
