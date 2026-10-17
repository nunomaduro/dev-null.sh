<?php

declare(strict_types=1);

use App\Actions\CreateCommit;
use App\Actions\DeleteUser;
use App\Dto\SshSignature;
use App\Enums\CommitStatus;
use App\Models\Commit;
use App\Models\SshKey;
use App\Models\User;
use App\Services\SignedCommits;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\SshKeyPair;

/**
 * @param array{payload: string, signature: string} $signed
 */
function submitPortableProof(array $signed): TestResponse
{
    return test()->call('POST', route('cli-commit.store'), server: [
        'CONTENT_TYPE' => 'text/plain; charset=utf-8',
        'HTTP_X_SSH_SIGNATURE' => $signed['signature'],
    ], content: $signed['payload']);
}

beforeEach(function (): void {
    $this->proofKeyPair = SshKeyPair::generate();
    $this->proofUser = User::factory()->create();

    SshKey::factory()->for($this->proofUser)->withPublicKey($this->proofKeyPair->publicKey)->create();
    fakeGitHubSshKeys($this->proofUser, [$this->proofKeyPair->publicKey]);
});

it('returns a portable receipt verifiable without contacting the site or GitHub', function (): void {
    $signed = signedCommit($this->proofKeyPair, 'It was DNS.');

    submitPortableProof($signed)->assertCreated();

    $commit = $this->proofUser->commits()->sole();
    $commit->forceFill(['status' => CommitStatus::Approved])->save();

    $response = $this->getJson(route('api-commit-proof.show', $commit));

    $response->assertOk()
        ->assertHeader('Cache-Control', 'no-store')
        ->assertJsonPath('namespace', SignedCommits::Namespace)
        ->assertJsonPath('fingerprint', $this->proofKeyPair->publicKey->fingerprint())
        ->assertJsonPath('public_key', $this->proofKeyPair->publicKey->toOpenSsh())
        ->assertJsonPath('displayed_body', 'It was DNS.');

    $payload = base64_decode($response->json('payload_base64'), true);
    $proof = SshSignature::parse($response->json('ssh_signature'));

    expect($payload)->toBe($signed['payload'])
        ->and($proof->verify($payload, SignedCommits::Namespace))->toBeTrue()
        ->and($proof->publicKey->fingerprint())->toBe($this->proofKeyPair->publicKey->fingerprint())
        ->and($commit->fresh()->toArray()['ssh_signed'])->toBeTrue();

    $this->getJson(route('api-commit.show', $commit))
        ->assertOk()
        ->assertJsonPath('data.ssh_signed', true)
        ->assertJsonMissingPath('data.signed_payload')
        ->assertJsonMissingPath('data.signer_fingerprint');
});

it('preserves the original bytes even when the displayed body is normalized', function (): void {
    $signed = signedCommit($this->proofKeyPair, '  It was DNS.  ');

    submitPortableProof($signed)->assertCreated();

    $commit = $this->proofUser->commits()->sole();
    $commit->forceFill(['status' => CommitStatus::Approved])->save();

    $response = $this->getJson(route('api-commit-proof.show', $commit))->assertOk();

    expect(base64_decode($response->json('payload_base64'), true))->toBe($signed['payload'])
        ->and($response->json('displayed_body'))->toBe('It was DNS.');
});

it('keeps pending and rejected proofs private even to authenticated visitors', function (): void {
    submitPortableProof(signedCommit($this->proofKeyPair, 'Not approved yet'))->assertCreated();

    $commit = $this->proofUser->commits()->sole();

    $this->getJson(route('api-commit-proof.show', $commit))->assertNotFound();

    $commit->forceFill(['status' => CommitStatus::Rejected])->save();

    $this->getJson(route('api-commit-proof.show', $commit))->assertNotFound();

    $commit->forceFill(['status' => CommitStatus::Approved])->save();

    $this->getJson(route('api-commit-proof.show', $commit))->assertOk();

    $commit->delete();

    $this->getJson(route('api-commit-proof.show', $commit))->assertNotFound();
});

it('does not assign signatures to unsigned web commits', function (): void {
    $commit = resolve(CreateCommit::class)->handle(User::factory()->create(), 'Unsigned web commit');

    $commit->forceFill(['status' => CommitStatus::Approved])->save();

    expect($commit->fresh()->toArray()['ssh_signed'])->toBeFalse()
        ->and(DB::table('commit_signatures')->count())->toBe(0);

    $this->getJson(route('api-commit-proof.show', $commit))->assertNotFound();
});

it('cannot forge an SSH-signed marker through the web composer', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('commit.store'), [
        'body' => 'Ordinary web commit',
        'signed_key_fingerprint' => 'SHA256:attacker',
        'verified_ssh_proof' => [
            'payload' => 'not signed',
            'signature' => 'not signed',
            'public_key' => 'not signed',
            'fingerprint' => 'SHA256:attacker',
        ],
    ])->assertRedirect();

    $commit = $user->commits()->sole();

    expect($commit->signer_fingerprint)->toBeNull()
        ->and(DB::table('commit_signatures')->where('commit_id', $commit->id)->exists())->toBeFalse();
});

it('refuses to publish an invalid or altered SSH signature', function (): void {
    $signed = signedCommit($this->proofKeyPair, 'It was DNS.');

    submitPortableProof([...$signed, 'payload' => $signed['payload'].'!'])->assertUnauthorized();

    expect(Commit::query()->count())->toBe(0)
        ->and(DB::table('commit_signatures')->count())->toBe(0);
});

it('never serves a modified proof that fails cryptographic verification', function (): void {
    submitPortableProof(signedCommit($this->proofKeyPair, 'Original message'))->assertCreated();

    $commit = $this->proofUser->commits()->sole();
    $commit->forceFill(['status' => CommitStatus::Approved])->save();

    DB::table('commit_signatures')->where('commit_id', $commit->id)->update(['payload' => 'Tampered']);

    $this->getJson(route('api-commit-proof.show', $commit))->assertNotFound();
});

it('purges retained public keys and signed bytes when an author deletes their account', function (): void {
    submitPortableProof(signedCommit($this->proofKeyPair, 'Please delete this'))->assertCreated();

    expect(DB::table('commit_signatures')->count())->toBe(1);

    resolve(DeleteUser::class)->handle($this->proofUser);

    expect(DB::table('commit_signatures')->count())->toBe(0);
});

it('erases proof bytes even when a signed reply survives as an anonymous tombstone', function (): void {
    $root = Commit::factory()->create();

    submitPortableProof(signedCommit($this->proofKeyPair, 'My signed reply', replyTo: $root->id))->assertCreated();

    $reply = $this->proofUser->commits()->sole();
    $reply->forceFill(['status' => CommitStatus::Approved])->save();

    Commit::factory()->for($reply, 'parent')->create();

    resolve(DeleteUser::class)->handle($this->proofUser);

    $tombstone = Commit::query()->withTrashed()->find($reply->id);

    expect($tombstone)->not->toBeNull()
        ->and($tombstone->user_id)->toBeNull()
        ->and($tombstone->signer_fingerprint)->toBeNull()
        ->and(DB::table('commit_signatures')->where('commit_id', $reply->id)->exists())->toBeFalse();
});

it('exports signatures as part of the author data export', function (): void {
    submitPortableProof(signedCommit($this->proofKeyPair, 'Exportable signed bytes'))->assertCreated();

    $export = resolve(\App\Actions\CreateExport::class)->handle($this->proofUser);
    $signatures = iterator_to_array($export['commit_signatures']);

    expect($signatures)->toHaveCount(1)
        ->and($signatures[0]['public_key'])->toBe($this->proofKeyPair->publicKey->toOpenSsh());
});
