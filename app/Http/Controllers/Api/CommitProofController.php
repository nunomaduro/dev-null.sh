<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Dto\SshSignature;
use App\Enums\CommitStatus;
use App\Models\Commit;
use App\Services\SignedCommits;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

final readonly class CommitProofController
{
    public function show(Commit $commit): JsonResponse
    {
        // No proof of pending, rejected, or deleted content is publicly accessible.
        abort_unless($commit->status === CommitStatus::Approved && ! $commit->trashed(), 404);

        /** @var stdClass|null $record */
        $record = DB::table('commit_signatures')->where('commit_id', $commit->id)->first();

        abort_if($record === null, 404);

        // A corrupt DB row must never produce a misleading signed receipt.
        try {
            $signature = SshSignature::parse((string) $record->signature);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        abort_unless(
            $signature->verify((string) $record->payload, SignedCommits::Namespace)
            && hash_equals((string) $record->public_key, $signature->publicKey->toOpenSsh())
            && hash_equals((string) $record->fingerprint, $signature->publicKey->fingerprint())
            && hash_equals((string) $record->fingerprint, (string) $commit->signer_fingerprint),
            404,
        );

        return response()->json([
            'version' => 1,
            'commit_id' => $commit->id,
            'html_url' => route('commit.show', [$commit->user, $commit]),
            'signer' => $commit->user?->username,
            'namespace' => SignedCommits::Namespace,
            'public_key' => $signature->publicKey->toOpenSsh(),
            'fingerprint' => $signature->publicKey->fingerprint(),
            'payload_base64' => base64_encode((string) $record->payload),
            'ssh_signature' => $signature->toArmored(),
            'displayed_body' => $commit->body,
            'note' => 'The signed payload is the original CLI submission. Display text may be normalized or expand emoji shortcodes. GitHub key ownership was checked at submission time, not reverified now.',
        ])->header('Cache-Control', 'no-store');
    }
}
