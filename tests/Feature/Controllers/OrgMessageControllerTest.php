<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

it('lets members push, read, and see who pulled', function (): void {
    $mark = User::factory()->create();
    [$nuno, $taylor] = followersOf($mark, 2);
    $org = createOrg($mark, [$nuno, $taylor]);
    $message = pushToOrg($mark, $org, 'ship it?');

    $this->actingAs($nuno)->getJson(route('inbox.index'))->assertJsonPath('conversations.0.ahead', 1)->assertJsonPath('unread', 1);
    $this->putJson(route('org-read.update', $org), ['through' => $message->id])->assertNoContent();
    $this->getJson(route('inbox.index'))->assertJsonPath('unread', 0);

    $members = collect($this->actingAs($mark)->getJson(route('org-message.index', $org))->assertOk()->json('members'))
        ->mapWithKeys(fn (array $member): array => [$member['user']['username'] => $member['read_through_id']]);

    expect($members[$nuno->username])->toBe($message->id)
        ->and($members[$taylor->username])->toBeLessThan($message->id);
});

it('hides pulls from people who do not share them', function (): void {
    $mark = User::factory()->create();
    [$nuno] = followersOf($mark, 1);
    $nuno->update(['pull_receipts' => false]);
    $org = createOrg($mark, [$nuno]);
    $message = pushToOrg($mark, $org);
    $this->actingAs($nuno)->putJson(route('org-read.update', $org), ['through' => $message->id]);

    $this->actingAs($mark)->getJson(route('org-message.index', $org))->assertJsonPath('members.1.read_through_id', 0);
});

it('shows who is typing', function (): void {
    $mark = User::factory()->create();
    [$nuno] = followersOf($mark, 1);
    $org = createOrg($mark, [$nuno]);

    $this->actingAs($nuno)->putJson(route('org-typing.update', $org))->assertNoContent();

    $this->actingAs($mark)->getJson(route('org-message.index', $org))->assertJsonPath('typing', [$nuno->username]);
    $this->actingAs($nuno)->getJson(route('org-message.index', $org))->assertJsonPath('typing', []);
});

it('checks all org typing indicators in one cache read', function (): void {
    $mark = User::factory()->create();
    [$nuno, $taylor, $quiet] = followersOf($mark, 3);
    $org = createOrg($mark, [$nuno, $taylor, $quiet]);

    $this->actingAs($nuno)->putJson(route('org-typing.update', $org))->assertNoContent();
    $this->actingAs($taylor)->putJson(route('org-typing.update', $org))->assertNoContent();

    $cache = Cache::store();
    Cache::shouldReceive('many')
        ->once()
        ->andReturnUsing(fn (array $keys): array => $cache->many($keys));

    $this->actingAs($mark)
        ->getJson(route('org-message.index', $org))
        ->assertOk()
        ->assertJsonPath('typing', [$nuno->username, $taylor->username]);
});

it('stashes an org for one member', function (): void {
    $mark = User::factory()->create();
    [$nuno] = followersOf($mark, 1);
    $org = createOrg($mark, [$nuno]);

    $this->actingAs($nuno)->postJson(route('org-archive.store', $org))->assertNoContent();
    $this->getJson(route('inbox.index', ['tab' => 'stashed']))->assertJsonCount(1, 'conversations');
    $this->deleteJson(route('org-archive.destroy', $org))->assertNoContent();
    $this->getJson(route('inbox.index'))->assertJsonCount(1, 'conversations');
});

it('finds orgs by name or by a member', function (string $term): void {
    $mark = User::factory()->create();
    [$nuno] = followersOf($mark, 1);
    $nuno->update(['username' => 'nunomaduro']);
    createOrg($mark, [$nuno], name: 'polling crew');

    $this->actingAs($mark)->getJson(route('inbox.index', ['q' => $term]))->assertJsonCount(1, 'conversations');
})->with(['polling', 'nunomad']);

it('keeps non-members out', function (): void {
    $mark = User::factory()->create();
    [$nuno] = followersOf($mark, 1);
    $org = createOrg($mark, [$nuno]);

    $this->actingAs(User::factory()->create())->postJson(route('org-message.store', $org), ['body' => 'hi', 'client_id' => fake()->uuid()])->assertNotFound();
});

it('replies only to messages in the history the member can see', function (): void {
    $mark = User::factory()->create();
    [$nuno, $taylor] = followersOf($mark, 2);
    $org = createOrg($mark, [$nuno]);
    $before = pushToOrg($nuno, $org, 'from before taylor joined');
    $this->actingAs($mark)->postJson(route('org-member.store', $org), ['members' => [$taylor->username], 'share_history' => false])->assertNoContent();
    $after = pushToOrg($nuno, $org, 'welcome taylor');

    $this->actingAs($taylor)->postJson(route('org-message.store', $org), ['body' => 'hi', 'client_id' => Str::uuid()->toString(), 'reply_to_id' => $before->id])
        ->assertUnprocessable()->assertJsonValidationErrors('reply_to_id');

    $this->postJson(route('org-message.store', $org), ['body' => 'thanks!', 'client_id' => Str::uuid()->toString(), 'reply_to_id' => $after->id])
        ->assertCreated()
        ->assertJsonPath('message.reply_to.user_id', $nuno->id)
        ->assertJsonPath('message.reply_to.body', 'welcome taylor');
});

it('pushes images and the link card the member chose', function (?string $choice, ?string $url): void {
    Queue::fake();
    Storage::fake();

    $mark = User::factory()->create();
    [$nuno] = followersOf($mark, 1);
    $org = createOrg($mark, [$nuno]);

    $this->actingAs($nuno)->post(route('org-message.store', $org), [
        'body' => 'release notes at https://laravel.com/docs and https://github.com/laravel',
        'client_id' => Str::uuid()->toString(),
        'link_preview_url' => $choice,
        'images' => [UploadedFile::fake()->image('notes.png', 20, 20)],
    ], ['Accept' => 'application/json'])->assertCreated()
        ->assertJsonPath('message.link_preview.url', $url)
        ->assertJsonCount(1, 'message.images');
})->with([
    'another link' => ['https://github.com/laravel', 'https://github.com/laravel'],
    'no card' => ['', null],
]);

it('pushes voice notes to the org', function (): void {
    Storage::fake();

    $mark = User::factory()->create();
    [$nuno] = followersOf($mark, 1);
    $org = createOrg($mark, [$nuno]);

    $this->actingAs($nuno)->post(route('org-message.store', $org), [
        'client_id' => Str::uuid()->toString(),
        'voice' => voiceNoteUpload(),
        'voice_waveform' => voiceNoteWaveform(),
    ], ['Accept' => 'application/json'])->assertCreated()
        ->assertJsonPath('message.voice.duration_ms', 2000);

    $this->actingAs($mark)->getJson(route('org-message.index', $org))
        ->assertJsonPath('messages.1.voice.duration_ms', 2000);
});

it('refreshes org mention eligibility without hiding members or restricting typed mentions', function (): void {
    $viewer = User::factory()->create(['username' => 'viewer']);
    [$followed, $muted, $blocked, $blocker, $ordinary] = followersOf($viewer, 5);
    $outsider = User::factory()->create(['username' => 'outside']);
    $org = createOrg($viewer, [$followed, $muted, $blocked, $blocker, $ordinary]);
    $viewer->following()->attach($followed);
    $viewer->muting()->attach($muted);
    $viewer->blocking()->attach($blocked);
    $blocker->blocking()->attach($viewer);

    $response = $this->actingAs($viewer)->getJson(route('org-message.index', $org))->assertOk();
    $members = collect($response->json('members'))->keyBy('user.id');

    expect($members)->toHaveCount(6);
    expect($members[$viewer->id]['suggests_mentions'])->toBeFalse();
    expect($members[$followed->id]['suggests_mentions'])->toBeTrue()
        ->and($members[$followed->id]['is_followed'])->toBeTrue();
    expect($members[$ordinary->id]['suggests_mentions'])->toBeTrue()
        ->and($members[$ordinary->id]['is_followed'])->toBeFalse();
    expect($members[$muted->id]['suggests_mentions'])->toBeFalse();
    expect($members[$blocked->id]['suggests_mentions'])->toBeFalse();
    expect($members[$blocker->id]['suggests_mentions'])->toBeFalse();

    $viewer->muting()->detach($muted);
    $viewer->following()->detach($followed);
    $this->getJson(route('org-message.index', [$org, 'after' => 0]))
        ->assertOk()
        ->assertJsonPath('members.1.is_followed', false)
        ->assertJsonPath('members.2.suggests_mentions', true);

    $message = pushToOrg($viewer, $org, '@outside still works');

    expect($message->mentions)->toBe(['outside' => $outsider->id]);
});

it('returns current suggestion members on incremental polls after a join or departure', function (): void {
    $viewer = User::factory()->create();
    [$original, $joining] = followersOf($viewer, 2);
    $org = createOrg($viewer, [$original]);

    $this->actingAs($viewer)->getJson(route('org-message.index', $org))
        ->assertJsonPath('members.*.user.id', [$viewer->id, $original->id]);

    $this->postJson(route('org-member.store', $org), ['members' => [$joining->username], 'share_history' => true])->assertNoContent();
    $this->deleteJson(route('org-member.destroy', [$org, $original]))->assertNoContent();

    $this->getJson(route('org-message.index', [$org, 'after' => 0]))
        ->assertOk()
        ->assertJsonPath('members.*.user.id', [$viewer->id, $joining->id])
        ->assertJsonPath('members.1.suggests_mentions', true);
});

it('hands every member the pinned messages, latest pinned first, whatever history they were given', function (): void {
    $mark = User::factory()->create();
    [$nuno, $taylor] = followersOf($mark, 2);
    $org = createOrg($mark, [$nuno]);
    $runbook = pushToOrg($nuno, $org, 'deploy runbook');
    $this->actingAs($mark)->postJson(route('org-member.store', $org), ['members' => [$taylor->username], 'share_history' => false])->assertNoContent();
    $welcome = pushToOrg($nuno, $org, 'welcome taylor');
    $this->actingAs($mark)->putJson(route('message-pin.update', $welcome))->assertOk();
    $this->travel(1)->minutes();
    $this->putJson(route('message-pin.update', $runbook))->assertOk();

    $thread = $this->actingAs($taylor)->getJson(route('org-message.index', $org))
        ->assertOk()
        ->assertJsonPath('pinned.*.id', [$runbook->id, $welcome->id])
        ->assertJsonPath('pinned.0.body', 'deploy runbook')
        ->assertJsonPath('pinned.0.user.username', $nuno->username)
        ->assertJsonPath('pinned.0.pinned_at', now()->startOfSecond()->toJSON());

    expect($thread->json('messages.*.id'))->toContain($welcome->id)->not->toContain($runbook->id);
});

it('hands polls the pinned messages only once they changed, and older pages none', function (): void {
    $mark = User::factory()->create();
    [$nuno] = followersOf($mark, 1);
    $org = createOrg($mark, [$nuno]);
    $message = pushToOrg($nuno, $org, 'deploy runbook');
    $version = $this->actingAs($mark)->getJson(route('org-message.index', $org))->assertJsonPath('pinned', [])->json('reactions_version');

    expect($this->getJson(route('org-message.index', [$org, 'after' => $message->id, 'reactions_after' => $version]))->json())
        ->toHaveKey('pinned', null);

    $this->putJson(route('message-pin.update', $message))->assertOk();

    $this->getJson(route('org-message.index', [$org, 'after' => $message->id, 'reactions_after' => $version]))
        ->assertJsonPath('pinned.*.id', [$message->id]);

    expect($this->getJson(route('org-message.index', [$org, 'before' => $message->id]))->json())
        ->toHaveKey('pinned', null);
});

it('leaves out what a pinned message replies to, which may be from before the member history', function (): void {
    $mark = User::factory()->create();
    [$nuno, $taylor] = followersOf($mark, 2);
    $org = createOrg($mark, [$nuno]);
    $question = pushToOrg($nuno, $org, 'where does the deploy key live?');
    $answer = $this->actingAs($mark)->postJson(route('org-message.store', $org), ['body' => 'in the vault', 'client_id' => Str::uuid()->toString(), 'reply_to_id' => $question->id])
        ->assertCreated()
        ->json('message.id');
    $this->postJson(route('org-member.store', $org), ['members' => [$taylor->username], 'share_history' => false])->assertNoContent();

    expect($this->putJson(route('message-pin.update', $answer))->assertOk()->json('pinned.0'))->not->toHaveKeys(['reply_to', 'reply_to_id']);

    $thread = $this->actingAs($taylor)->getJson(route('org-message.index', $org))->assertJsonPath('pinned.0.id', $answer);

    expect($thread->json('pinned.0'))->not->toHaveKeys(['reply_to', 'reply_to_id'])
        ->and($thread->getContent())->not->toContain('where does the deploy key live?');
});

it('lets members reply to a pinned message from before their history', function (): void {
    $mark = User::factory()->create();
    [$nuno, $taylor] = followersOf($mark, 2);
    $org = createOrg($mark, [$nuno]);
    $runbook = pushToOrg($nuno, $org, 'deploy runbook');
    $this->actingAs($mark)->postJson(route('org-member.store', $org), ['members' => [$taylor->username], 'share_history' => false])->assertNoContent();
    $this->putJson(route('message-pin.update', $runbook))->assertOk();

    $this->actingAs($taylor)->postJson(route('org-message.store', $org), ['body' => 'thanks!', 'client_id' => Str::uuid()->toString(), 'reply_to_id' => $runbook->id])
        ->assertCreated()
        ->assertJsonPath('message.reply_to.body', 'deploy runbook');
});
