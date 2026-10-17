<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cli;

use App\Actions\CreateCommit;
use App\Enums\CommitStatus;
use App\Http\Requests\CreateCliCommitRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Response;

final readonly class CommitController
{
    public function store(CreateCliCommitRequest $request, #[CurrentUser] User $user, CreateCommit $action): Response
    {
        /** @var array{payload: string, signature: string, public_key: string, fingerprint: string} $verifiedProof */
        $verifiedProof = $request->attributes->get('verified_ssh_proof');

        $commit = $action->handle(
            $user,
            $request->string('body')->value(),
            $request->parent(),
            signedProof: $verifiedProof,
        );

        $url = route('commit.show', [$user, $commit]);

        $message = $commit->status === CommitStatus::Pending
            ? __('Committed: :url (pending review, only you can see it until it is approved)', ['url' => $url])
            : __('Committed: :url', ['url' => $url]);

        return response($message."\n", 201)->header('Content-Type', 'text/plain; charset=utf-8');
    }
}
