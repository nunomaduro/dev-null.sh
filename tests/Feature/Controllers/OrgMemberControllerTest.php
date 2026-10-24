<?php

declare(strict_types=1);

use App\Enums\OrgEvent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageImage;
use App\Models\MessageVoice;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

it('adds people with or without the history', function (bool $sharesHistory, int $visible): void {
    $mark = User::factory()->create();
    [$nuno, $taylor] = followersOf($mark, 2);
    $org = createOrg($mark, [$nuno]);
    pushToOrg($mark, $org, 'before taylor joined');

    $this->actingAs($mark)->postJson(route('org-member.store', $org), ['members' => [$taylor->username], 'share_history' => $sharesHistory])->assertNoContent();

    $messages = collect($this->actingAs($taylor)->getJson(route('org-message.index', $org))->json('messages'));

    expect($messages->whereNull('event')->count())->toBe($visible)
        ->and($messages->last()['event'])->toBe('added');
    $this->getJson(route('inbox.index'))->assertJsonPath('conversations.0.ahead', 0);
})->with([
    'with the history' => [true, 1],
    'from now on' => [false, 0],
]);

it('lets only maintainers add, up to a hundred', function (): void {
    $mark = User::factory()->create();
    $followers = followersOf($mark, Conversation::MAX_MEMBERS);
    [$last, $extra] = array_slice($followers, -2);
    $org = createOrg($mark, array_slice($followers, 0, -2));

    $this->actingAs($followers[0])->postJson(route('org-member.store', $org), ['members' => [$last->username], 'share_history' => true])->assertForbidden();
    $this->actingAs($mark)->postJson(route('org-member.store', $org), ['members' => [$last->username, $extra->username], 'share_history' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('members');
    $this->postJson(route('org-member.store', $org), ['members' => ['nobody-by-that-name'], 'share_history' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('members.0');
    $this->postJson(route('org-member.store', $org), ['members' => [$last->username], 'share_history' => true])->assertNoContent();
    $this->postJson(route('org-member.store', $org), ['members' => [$extra->username], 'share_history' => true])
        ->assertUnprocessable()->assertJsonValidationErrors(['members.0' => 'the org is full ('.Conversation::MAX_MEMBERS.')']);
});

it('removes people, who then lose access', function (): void {
    $mark = User::factory()->create();
    [$nuno, $taylor] = followersOf($mark, 2);
    $org = createOrg($mark, [$nuno, $taylor]);

    $this->actingAs($nuno)->deleteJson(route('org-member.destroy', [$org, $taylor]))->assertForbidden();
    $this->actingAs($mark)->deleteJson(route('org-member.destroy', [$org, $taylor]))->assertNoContent();

    expect(Message::query()->latest('id')->firstOrFail()->event)->toBe(OrgEvent::Removed);
    $this->actingAs($taylor)->getJson(route('org-message.index', $org))->assertNotFound();
    $this->getJson(route('inbox.index'))->assertJsonPath('conversations', []);
});

it('hands the org to the longest-standing member when the last maintainer leaves', function (): void {
    $mark = User::factory()->create();
    [$nuno, $taylor] = followersOf($mark, 2);
    $org = createOrg($mark, [$nuno, $taylor]);

    $this->actingAs($mark)->deleteJson(route('org-member.destroy', [$org, $mark]))->assertNoContent();

    expect(Conversation::query()->findOrFail($org)->roleOf($nuno)?->value)->toBe('maintainer')
        ->and(Message::query()->latest('id')->firstOrFail()->event)->toBe(OrgEvent::Inherited);

    $this->actingAs($taylor)->deleteJson(route('org-member.destroy', [$org, $taylor]))->assertNoContent();
    $this->actingAs($nuno)->deleteJson(route('org-member.destroy', [$org, $nuno]))->assertNoContent();

    expect(Conversation::query()->find($org))->toBeNull();
});

it('promotes and demotes, keeping at least one maintainer', function (): void {
    $mark = User::factory()->create();
    [$nuno] = followersOf($mark, 1);
    $org = createOrg($mark, [$nuno]);

    $this->actingAs($nuno)->putJson(route('org-member.update', [$org, $nuno]), ['role' => 'maintainer'])->assertForbidden();
    $this->actingAs($mark)->putJson(route('org-member.update', [$org, $mark]), ['role' => 'contributor'])
        ->assertUnprocessable()->assertJsonValidationErrors(['role' => 'An org needs at least one maintainer.']);
    $this->putJson(route('org-member.update', [$org, $nuno]), ['role' => 'maintainer'])->assertNoContent();

    expect(Message::query()->latest('id')->firstOrFail()->event)->toBe(OrgEvent::Promoted);

    $this->putJson(route('org-member.update', [$org, $mark]), ['role' => 'contributor'])->assertNoContent();

    expect(Message::query()->latest('id')->firstOrFail()->event)->toBe(OrgEvent::Demoted)
        ->and(Conversation::query()->findOrFail($org)->isMaintainedBy($mark))->toBeFalse();
});

it('hides membership changes from people outside the org', function (): void {
    $mark = User::factory()->create();
    [$nuno] = followersOf($mark, 1);
    $org = createOrg($mark, [$nuno]);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->deleteJson(route('org-member.destroy', [$org, $nuno]))->assertNotFound();
    $this->putJson(route('org-member.update', [$org, $nuno]), ['role' => 'maintainer'])->assertNotFound();
    $this->postJson(route('org-member.store', $org), ['members' => [$stranger->username], 'share_history' => true])->assertNotFound();
});

it('erases the images and voice notes of an org everyone has left', function (): void {
    Storage::fake();

    $mark = User::factory()->create();
    [$nuno] = followersOf($mark, 1);
    $org = createOrg($mark, [$nuno]);
    $image = MessageImage::factory()->for(pushToOrg($mark, $org))->create();
    $voice = MessageVoice::factory()->for(pushToOrg($nuno, $org))->create();
    Storage::disk()->put($image->path, 'image');
    Storage::disk()->put($voice->path, 'voice');
    $elsewhere = MessageVoice::factory()->create();
    Storage::disk()->put($elsewhere->path, 'voice');

    $this->actingAs($nuno)->deleteJson(route('org-member.destroy', [$org, $nuno]))->assertNoContent();

    expect(Storage::disk()->allFiles())->toHaveCount(3);

    $this->actingAs($mark)->deleteJson(route('org-member.destroy', [$org, $mark]))->assertNoContent();

    expect(Storage::disk()->allFiles())->toBe([$elsewhere->path]);
});

it('ignores members already in the org and preserves participant integrity', function (): void {
    $mark = User::factory()->create();
    [$nuno, $taylor] = followersOf($mark, 2);
    $org = Conversation::query()->findOrFail(createOrg($mark, [$nuno]));

    resolve(App\Actions\CreateOrgMembers::class)->handle($mark, $org, collect([$nuno, $taylor]), true);

    expect($org->participants()->count())->toBe(3);
});
