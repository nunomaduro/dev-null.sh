<?php

declare(strict_types=1);

use App\Models\User;

beforeEach(function (): void {
    $this->user = User::factory()->withRemotes()->create(['username' => 'octocat']);
    $this->keyPair = apiKeyPairFor($this->user);
});

it('loads an org with its members, events and who is typing, as the web thread does', function (): void {
    [$nuno, $taylor] = followersOf($this->user, 2);
    $org = createOrg($this->user, [$nuno, $taylor], 'dev/null core');
    $message = pushToOrg($nuno, $org, 'ship it');

    $this->actingAs($taylor)->putJson(route('org-typing.update', $org))->assertNoContent();
    $this->actingAs($taylor)->putJson(route('org-read.update', $org), ['through' => $message->id])->assertNoContent();

    signedApiRequest($this->keyPair, 'GET', route('api-org-message.index', $org))
        ->assertOk()
        ->assertJsonPath('data.org', ['id' => $org, 'name' => 'dev/null core'])
        ->assertJsonCount(2, 'data.messages')
        ->assertJsonPath('data.messages.0.event', 'created')
        ->assertJsonPath('data.messages.0.user.username', 'octocat')
        ->assertJsonPath('data.messages.0.subject', null)
        ->assertJsonPath('data.messages.1.id', $message->id)
        ->assertJsonPath('data.messages.1.body', 'ship it')
        ->assertJsonPath('data.messages.1.user.username', $nuno->username)
        ->assertJsonPath('data.messages.1.user.lane', $nuno->lane())
        ->assertJsonPath('data.members.0.user.username', 'octocat')
        ->assertJsonPath('data.members.0.role', 'maintainer')
        ->assertJsonPath('data.members.1.user.username', $nuno->username)
        ->assertJsonPath('data.members.1.role', 'contributor')
        ->assertJsonPath('data.members.2.read_through_id', $message->id)
        ->assertJsonPath('data.typing', [$taylor->username])
        ->assertJsonPath('data.role', 'maintainer')
        ->assertJsonPath('data.is_archived', false)
        ->assertJsonPath('data.access', 'open');
});

it('names who an event is about', function (): void {
    [$nuno, $taylor] = followersOf($this->user, 2);
    $org = createOrg($this->user, [$nuno]);

    $this->actingAs($this->user)->postJson(route('org-member.store', $org), ['members' => [$taylor->username], 'share_history' => true])->assertNoContent();

    signedApiRequest($this->keyPair, 'GET', route('api-org-message.index', ['org' => $org, 'after' => 0]))
        ->assertOk()
        ->assertJsonPath('data.messages.1.event', 'added')
        ->assertJsonPath('data.messages.1.subject_id', $taylor->id)
        ->assertJsonPath('data.messages.1.subject.username', $taylor->username);
});

it('starts where the member history starts', function (): void {
    [$nuno, $taylor] = followersOf($this->user, 2);
    $org = createOrg($this->user, [$nuno]);
    pushToOrg($nuno, $org, 'before taylor');

    $this->actingAs($this->user)->postJson(route('org-member.store', $org), ['members' => [$taylor->username], 'share_history' => false])->assertNoContent();

    signedApiRequest(apiKeyPairFor($taylor), 'GET', route('api-org-message.index', $org))
        ->assertOk()
        ->assertJsonPath('data.messages.0.event', 'added')
        ->assertJsonPath('data.role', 'contributor');
});

it('hands over the pinned messages as the thread shows them, until they are unchanged', function (): void {
    [$nuno] = followersOf($this->user, 1);
    $org = createOrg($this->user, [$nuno]);
    $message = pushToOrg($nuno, $org, 'deploy runbook');
    $this->actingAs($this->user)->putJson(route('message-pin.update', $message))->assertOk();

    $version = signedApiRequest($this->keyPair, 'GET', route('api-org-message.index', $org))
        ->assertOk()
        ->assertJsonPath('data.pinned.0.id', $message->id)
        ->assertJsonPath('data.pinned.0.body', 'deploy runbook')
        ->assertJsonPath('data.pinned.0.user.lane', $nuno->lane())
        ->assertJsonPath('data.pinned.0.created_at', $message->created_at->toIso8601ZuluString())
        ->json('data.reactions_version');

    expect(signedApiRequest($this->keyPair, 'GET', route('api-org-message.index', ['org' => $org, 'after' => $message->id, 'reactions_after' => $version]))->json('data'))
        ->toHaveKey('pinned', null);
});

it('keeps people out of orgs they are not in', function (): void {
    $maintainer = User::factory()->withRemotes()->create();
    [$nuno] = followersOf($maintainer, 1);
    $org = createOrg($maintainer, [$nuno]);

    signedApiRequest($this->keyPair, 'GET', route('api-org-message.index', $org))->assertNotFound();
});

it('is not there while the user has Remotes off', function (): void {
    [$nuno] = followersOf($this->user, 1);
    $org = createOrg($this->user, [$nuno]);

    $this->user->update(['remotes_enabled' => false]);

    signedApiRequest($this->keyPair, 'GET', route('api-org-message.index', $org))->assertNotFound();
});

it('rejects requests that are not signed', function (): void {
    [$nuno] = followersOf($this->user, 1);
    $org = createOrg($this->user, [$nuno]);

    $this->getJson(route('api-org-message.index', $org))->assertUnauthorized();
});
