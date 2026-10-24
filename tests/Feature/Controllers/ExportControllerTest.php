<?php

declare(strict_types=1);

use App\Actions\CreateCommitTranslation;
use App\Actions\DeleteUser;
use App\Ai\Agents\CommitTranslator;
use App\Enums\ModerationReason;
use App\Enums\NotificationType;
use App\Models\Commit;
use App\Models\CommitImage;
use App\Models\Device;
use App\Models\Message;
use App\Models\MessageVoice;
use App\Models\Notification;
use App\Models\Poll;
use App\Models\PollVote;
use App\Models\SshKey;
use App\Models\User;
use App\Models\UserTimeout;
use App\Services\CommitTranslations;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

it('requires authentication to download personal data', function (): void {
    $this->get(route('export'))->assertRedirectToRoute('home');
});

it('exports a surviving quote reference without the deleted authors tombstone content', function (): void {
    $user = User::factory()->create();
    $deleted = User::factory()->create(['username' => 'deletedquoteauthor']);
    $root = Commit::factory()->create();
    $quoted = Commit::factory()->for($deleted)->for($root, 'parent')->create(['body' => 'Erased quoted words']);
    $reply = Commit::factory()->for($user)->for($quoted, 'parent')->create();
    $quote = Commit::factory()->for($user)->for($quoted, 'quotedCommit')->create();

    resolve(DeleteUser::class)->handle($deleted);

    $response = $this->actingAs($user)->get(route('export'));
    $data = json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR);
    $commits = collect($data['commits'])->keyBy('id');

    expect($commits->keys()->sort()->values()->all())->toBe(collect([$reply->id, $quote->id])->sort()->values()->all())
        ->and($commits[$quote->id]['quoted_commit_id'])->toBe($quoted->id)
        ->and($commits[$reply->id]['parent_id'])->toBe($quoted->id)
        ->and($response->streamedContent())->not->toContain('deletedquoteauthor', 'Erased quoted words');
});

it('downloads a versioned private JSON file with an explicit profile and empty lists', function (): void {
    $user = User::factory()->create([
        'name' => 'Zoë Example',
        'bio' => "Building tools.\nKeeping them small.",
        'email' => 'zoe@example.test',
        'company' => null,
        'has_sponsors_listing' => true,
        'preferred_lane' => 3,
        'timezone' => 'Europe/Budapest',
        'show_local_time' => true,
        'translation_language' => 'ja',
        'automatic_translation' => false,
        'lane_color' => '#1a2b3c',
        'pasteis_mode' => true,
        'pasteis_received' => 17,
        'pasteis_given_today' => 6,
        'pasteis_given_on' => '2026-10-15',
        'last_seen_at' => now()->toDateString(),
        'remember_token' => 'never-export-this-token',
    ]);
    $original = $user->fresh()->getAttributes();

    $response = $this->actingAs($user)->get(route('export'));

    $response->assertOk()
        ->assertStreamed()
        ->assertDownload('devnull-export-'.now()->format('Y-m-d-His').'.json')
        ->assertHeader('Content-Type', 'application/json; charset=UTF-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Accel-Buffering', 'no');
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');

    $data = json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR);

    expect($data)->toBe([
        'schema_version' => 2,
        'exported_at' => now()->toIso8601String(),
        'profile' => [
            'github_id' => $user->github_id,
            'username' => $user->username,
            'name' => 'Zoë Example',
            'email' => 'zoe@example.test',
            'avatar_url' => $user->avatar_url,
            'bio' => "Building tools.\nKeeping them small.",
            'location' => $user->location,
            'website' => $user->website,
            'company' => null,
            'has_sponsors_listing' => true,
            'preferred_lane' => 3,
            'timezone' => 'Europe/Budapest',
            'show_local_time' => true,
            'translation_language' => 'ja',
            'automatic_translation' => false,
            'lane_color' => '#1a2b3c',
            'pasteis_mode' => true,
            'pasteis_received' => 17,
            'pasteis_given_today' => 6,
            'pasteis_given_on' => '2026-10-15',
            'remotes_enabled' => true,
            'remotes_from' => 'following',
            'pull_receipts' => true,
            'pinned_commit_id' => null,
            'live_platform' => null,
            'live_url' => null,
            'live_ends_at' => null,
            'last_seen_at' => now()->toDateString(),
            'commit_rules_accepted_at' => $user->commit_rules_accepted_at->toIso8601String(),
            'emails_unsubscribed_at' => null,
            'created_at' => $user->created_at->toIso8601String(),
            'updated_at' => $user->updated_at->toIso8601String(),
        ],
        'commits' => [],
        'polls' => [],
        'poll_votes' => [],
        'stars' => [],
        'following' => [],
        'followers' => [],
        'muting' => [],
        'blocking' => [],
        'dismissed_suggestions' => [],
        'messages' => [],
        'remote_mutes' => [],
        'remote_pins' => [],
        'notifications' => [],
        'ssh_keys' => [],
        'devices' => [],
        'timeouts' => [],
        'sessions' => [],
    ]);
    expect($response->streamedContent())->not->toContain('never-export-this-token')
        ->and($user->fresh()->getAttributes())->toBe($original);
});

it('exports when the user accepted the commit rules and their timeouts, without who gave them', function (): void {
    $user = User::factory()->create(['commit_rules_accepted_at' => now()->subWeek()]);
    $first = UserTimeout::factory()->for($user)->ended()->create(['reason' => ModerationReason::EngagementBait, 'created_at' => now()->subDays(3)]);
    $second = UserTimeout::factory()->for($user)->create(['reason' => ModerationReason::Spam, 'ends_at' => now()->addHour()]);
    UserTimeout::factory()->create();

    $response = $this->actingAs($user)->get(route('export'));
    $data = json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR);

    expect($data['profile']['commit_rules_accepted_at'])->toBe(now()->subWeek()->toIso8601String())
        ->and($data['timeouts'])->toBe([
            [
                'reason' => 'engagement_bait',
                'ends_at' => $first->ends_at->toIso8601String(),
                'created_at' => $first->created_at->toIso8601String(),
            ],
            [
                'reason' => 'spam',
                'ends_at' => $second->ends_at->toIso8601String(),
                'created_at' => $second->created_at->toIso8601String(),
            ],
        ]);
});

it('exports the remotes the user muted, without the mutes of others', function (): void {
    config(['remotes.requests' => true, 'remotes.everyone' => true]);
    $user = User::factory()->withRemotes()->create();
    $nuno = User::factory()->withRemotes()->create(['username' => 'nunomaduro']);
    $taylor = User::factory()->withRemotes()->create();
    sendChatMessage($nuno, $user);
    sendChatMessage($taylor, $user);
    $org = createOrg($user, followersOf($user, 1), 'dev/null core');
    $this->actingAs($user)->putJson(route('conversation-mute.update', $nuno), ['duration' => 'week'])->assertOk();
    $this->putJson(route('org-mute.update', $org), ['duration' => 'forever'])->assertOk();
    $this->actingAs($taylor)->putJson(route('conversation-mute.update', $user), ['duration' => 'forever'])->assertOk();

    $response = $this->actingAs($user)->get(route('export'));
    $data = json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR);

    expect($data['remote_mutes'])->toBe([
        ['to' => 'nunomaduro', 'muted_until_at' => now()->startOfSecond()->addWeek()->toIso8601String()],
        ['to' => 'org: dev/null core', 'muted_until_at' => '9999-12-31T00:00:00+00:00'],
    ]);
});

it('exports the pinned commit id', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->for($user)->create();
    $user->forceFill(['pinned_commit_id' => $commit->id])->save();

    $response = $this->actingAs($user)->get(route('export'));
    $data = json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR);

    expect($data['profile']['pinned_commit_id'])->toBe($commit->id);
});

it('exports all the signed-in users commits including replies, deleted ones, and all moderation statuses', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $parent = Commit::factory()->for($other)->rejected()->create(['body' => 'Another developer private parent body']);
    $approved = Commit::factory()->for($user)->create(['body' => "Shipped Unicode support 🚀\nNext: docs.", 'pasteis_count' => 3, 'topic_decision' => true]);
    $pending = Commit::factory()->for($user)->pending()->create();
    $rejected = Commit::factory()->for($user)->rejected()->create();
    $reply = Commit::factory()->for($user)->create(['parent_id' => $parent->id]);
    $quote = Commit::factory()->for($user)->create(['quoted_commit_id' => $parent->id]);
    $deleted = Commit::factory()->for($user)->create(['body' => 'Deleted content is still stored']);
    $deleted->delete();

    $otherReply = Commit::factory()->for($other)->create(['parent_id' => $approved->id, 'body' => 'Another developer reply body']);
    Commit::factory()->for($other)->pending()->create();

    CommitTranslator::fake([['source_language' => 'en', 'body' => 'Unicode-ondersteuning geleverd 🚀']]);
    resolve(CreateCommitTranslation::class)->handle($approved, 'nl');

    $response = $this->actingAs($user)->get(route('export', ['user_id' => $other->id]));
    $data = json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR);
    $commits = collect($data['commits'])->keyBy('id');

    expect($commits->keys()->sort()->values()->all())->toBe(collect([
        $approved->id, $pending->id, $rejected->id, $reply->id, $quote->id, $deleted->id,
    ])->sort()->values()->all());
    expect($commits[$approved->id])->toBe([
        'id' => $approved->id,
        'parent_id' => null,
        'quoted_commit_id' => null,
        'body' => $approved->body,
        'cached_translations' => [
            'source_language' => 'en',
            'translations' => ['nl' => 'Unicode-ondersteuning geleverd 🚀'],
            'expires_at' => now()->addDays(7)->getTimestamp(),
        ],
        'status' => 'approved',
        'topic_decision' => true,
        'topics' => [],
        'pasteis_count' => 3,
        'images' => [],
        'created_at' => $approved->created_at->toIso8601String(),
        'updated_at' => $approved->updated_at->toIso8601String(),
        'deleted_at' => null,
    ])
        ->and($commits[$pending->id]['status'])->toBe('pending')
        ->and($commits[$rejected->id]['status'])->toBe('rejected')
        ->and($commits[$reply->id]['parent_id'])->toBe($parent->id)
        ->and($commits[$quote->id]['quoted_commit_id'])->toBe($parent->id)
        ->and($commits[$deleted->id]['body'])->toBe('Deleted content is still stored')
        ->and($commits[$deleted->id]['deleted_at'])->toBe(now()->toIso8601String())
        ->and($response->streamedContent())->not->toContain($parent->body, $otherReply->body);
});

it('exports commit image urls in order', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->for($user)->create();
    $second = CommitImage::factory()->for($commit)->create(['position' => 1]);
    $first = CommitImage::factory()->for($commit)->create(['position' => 0]);

    $response = $this->actingAs($user)->get(route('export'));
    $data = json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR);

    expect($data['commits'][0]['images'])->toBe([
        route('commit-image.show', $first),
        route('commit-image.show', $second),
    ])->and($response->streamedContent())->not->toContain($first->path);
});

it('exports starred commit ids, including deleted commits, but not other users stars', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $starred = Commit::factory()->for($other)->create();
    $deletedStarred = Commit::factory()->for($other)->create();
    $saved = Commit::factory()->for($other)->create();
    $user->starredCommits()->attach([$starred->id, $deletedStarred->id]);
    $deletedStarred->delete();
    $otherStarred = Commit::factory()->create();
    $other->starredCommits()->attach($otherStarred);

    $response = $this->actingAs($user)->get(route('export'));
    $data = json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR);

    expect(collect($data['stars'])->sort()->values()->all())->toBe(collect([$starred->id, $deletedStarred->id])->sort()->values()->all())
        ->and($response->streamedContent())->not->toContain($otherStarred->id, $saved->body, $starred->body);
});

it('exports the polls of live commits and votes without other voters', function (): void {
    $user = User::factory()->create();
    $poll = Poll::factory()->for(Commit::factory()->for($user))->withOptions('Tabs', 'Spaces')->create();
    $deleted = Poll::factory()->for(Commit::factory()->for($user)->state(['deleted_at' => now()]))->withOptions('Never', 'Exported')->create();
    $other = Poll::factory()->withOptions('Vim', 'Emacs')->create();
    $vote = PollVote::factory()->for($other->options[1], 'option')->for($user)->create();
    $voter = User::factory()->create();
    PollVote::factory()->for($poll->options[0], 'option')->for($voter)->create();

    $response = $this->actingAs($user)->get(route('export'));
    $data = json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR);

    expect($data['polls'])->toBe([[
        'commit_id' => $poll->commit_id,
        'duration_minutes' => 1440,
        'opened_at' => $poll->opened_at?->toIso8601String(),
        'options' => ['Tabs', 'Spaces'],
        'created_at' => $poll->created_at->toIso8601String(),
    ]])
        ->and($data['poll_votes'])->toBe([[
            'commit_id' => $other->commit_id,
            'option' => 'Emacs',
            'created_at' => $vote->created_at->toIso8601String(),
        ]])
        ->and($response->streamedContent())->not->toContain($voter->id, $voter->username, $deleted->options[0]->label);
});

it('exports surviving replies and polls after another account is deleted', function (): void {
    $user = User::factory()->create();
    $deleted = User::factory()->create();
    $removed = Poll::factory()->for(Commit::factory()->for($deleted))->withOptions('Removed A', 'Removed B')->create();
    PollVote::factory()->for($removed->options[0], 'option')->for($user)->create();
    $kept = Poll::factory()->for(Commit::factory()->for($user))->withOptions('Kept A', 'Kept B')->create();
    $parent = Commit::factory()->for($deleted)->for(Commit::factory(), 'parent')->create();
    $reply = Commit::factory()->for($user)->for($parent, 'parent')->create();

    resolve(DeleteUser::class)->handle($deleted);

    $response = $this->actingAs($user)->get(route('export'));
    $data = json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR);

    expect(collect($data['commits'])->keyBy('id')->get($reply->id)['parent_id'])->toBe($parent->id)
        ->and(array_column($data['polls'], 'commit_id'))->toBe([$kept->commit_id])
        ->and($data['polls'][0]['options'])->toBe(['Kept A', 'Kept B'])
        ->and($data['poll_votes'])->toBe([])
        ->and($response->streamedContent())->not->toContain('Removed A', 'Removed B', $deleted->username);
});

it('exports following and follower usernames without private identity fields', function (): void {
    $user = User::factory()->create();
    $followed = User::factory()->create(['username' => 'demo-developer', 'email' => 'private@example.test', 'remember_token' => 'secret-token']);
    $follower = User::factory()->create(['username' => 'demo-follower', 'email' => 'follower@example.test']);
    $stranger = User::factory()->create();
    $user->following()->attach($followed);
    $user->followers()->attach($follower);
    $follower->following()->attach($stranger);

    $response = $this->actingAs($user)->get(route('export'));
    $data = json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR);

    expect($data['following'])->toBe([['username' => 'demo-developer']])
        ->and($data['followers'])->toBe([['username' => 'demo-follower']])
        ->and($response->streamedContent())->not->toContain(
            $followed->email, $followed->github_id, $followed->id, $followed->remember_token,
            $follower->email, $follower->id, $stranger->username,
        );
});

it('exports received notifications without revealing moderators', function (): void {
    $user = User::factory()->create();
    $friend = User::factory()->create(['username' => 'friendly-dev']);
    $moderator = User::factory()->create(['username' => 'secret-moderator']);
    $commit = Commit::factory()->for($user)->rejected()->create();
    Notification::factory()->for($user, 'recipient')->for($friend, 'actor')->read()->create();
    Notification::factory()->for($user, 'recipient')->for($moderator, 'actor')->create([
        'type' => NotificationType::Rejected,
        'commit_id' => $commit->id,
        'reason' => 'Looks like spam.',
    ]);
    Notification::factory()->for($user, 'recipient')->leveledUp(3)->create();
    Notification::factory()->for($friend, 'recipient')->for($moderator, 'actor')->create();

    $response = $this->actingAs($user)->get(route('export'));
    $data = json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR);
    $notifications = collect($data['notifications'])->keyBy('type');

    expect($notifications)->toHaveCount(3)
        ->and($notifications['followed'])->toBe([
            'type' => 'followed',
            'actor' => 'friendly-dev',
            'commit_id' => null,
            'reason' => null,
            'level' => null,
            'read_at' => now()->toIso8601String(),
            'created_at' => now()->toIso8601String(),
        ])
        ->and($notifications['rejected'])->toBe([
            'type' => 'rejected',
            'actor' => null,
            'commit_id' => $commit->id,
            'reason' => 'Looks like spam.',
            'level' => null,
            'read_at' => null,
            'created_at' => now()->toIso8601String(),
        ])
        ->and($notifications['leveled_up'])->toBe([
            'type' => 'leveled_up',
            'actor' => null,
            'commit_id' => null,
            'reason' => null,
            'level' => 3,
            'read_at' => null,
            'created_at' => now()->toIso8601String(),
        ])
        ->and($response->streamedContent())->not->toContain('secret-moderator');
});

it('exports ssh key fingerprints and sessions without session ids or payloads', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $key = SshKey::factory()->for($user)->create();
    $otherKey = SshKey::factory()->for($other)->create();
    DB::table('sessions')->insert([
        [
            'id' => 'secret-session-id',
            'user_id' => $user->id,
            'ip_address' => '203.0.113.7',
            'user_agent' => 'Mozilla/5.0 Test',
            'payload' => 'secret-session-payload',
            'last_activity' => now()->getTimestamp(),
        ],
        [
            'id' => 'other-session-id',
            'user_id' => $other->id,
            'ip_address' => '198.51.100.9',
            'user_agent' => 'Other Agent',
            'payload' => 'other-session-payload',
            'last_activity' => now()->getTimestamp(),
        ],
    ]);

    $response = $this->actingAs($user)->get(route('export'));
    $data = json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR);

    expect($data['ssh_keys'])->toBe([$key->fingerprint])
        ->and($data['sessions'])->toBe([[
            'ip_address' => '203.0.113.7',
            'user_agent' => 'Mozilla/5.0 Test',
            'last_activity' => now()->getTimestamp(),
        ]])
        ->and($response->streamedContent())->not->toContain(
            'secret-session-id', 'secret-session-payload', 'other-session-id', '198.51.100.9', $otherKey->fingerprint,
        );
});

it('exports the devices approved for the app, oldest first', function (): void {
    $user = User::factory()->create();
    $older = Device::factory()->for($user)->create(['name' => 'devnull on iPad', 'approved_at' => now()->subWeek(), 'last_used_at' => now()->subDay()]);
    $newer = Device::factory()->for($user)->create(['name' => 'devnull on iPhone', 'approved_at' => now()->subDay()]);
    $other = Device::factory()->create();

    $response = $this->actingAs($user)->get(route('export'));
    $data = json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR);

    expect($data['devices'])->toBe([
        ['name' => 'devnull on iPad', 'fingerprint' => $older->fingerprint, 'approved_at' => now()->subWeek()->toIso8601String(), 'last_used_at' => now()->subDay()->toIso8601String()],
        ['name' => 'devnull on iPhone', 'fingerprint' => $newer->fingerprint, 'approved_at' => now()->subDay()->toIso8601String(), 'last_used_at' => null],
    ])
        ->and($response->streamedContent())->not->toContain($other->fingerprint);
});

it('exports all records across chunk boundaries without omissions or duplicates', function (): void {
    config(['cache.default' => 'database']);
    $user = User::factory()->create();
    $commits = Commit::factory()->for($user)->count(501)->create();
    $following = User::factory()->count(501)->create();
    $user->following()->attach($following->modelKeys());
    $translated = $commits->sortBy('id')->last();
    $cached = ['source_language' => 'de', 'translations' => ['en' => 'Good morning'], 'expires_at' => now()->addDay()->getTimestamp()];
    Cache::put(resolve(CommitTranslations::class)->key($translated), $cached, 60);

    $response = $this->actingAs($user)->get(route('export'));
    DB::enableQueryLog();
    DB::flushQueryLog();
    $data = json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR);
    $cacheReads = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "cache"'));
    DB::disableQueryLog();

    expect(collect($data['commits'])->pluck('id')->sort()->values()->all())->toBe($commits->pluck('id')->sort()->values()->all())
        ->and(collect($data['following'])->pluck('username')->sort()->values()->all())->toBe($following->pluck('username')->sort()->values()->all())
        ->and(collect($data['commits'])->keyBy('id')[$translated->id]['cached_translations'])->toBe($cached)
        ->and($data['commits'][0]['cached_translations'])->toBeNull()
        ->and($cacheReads)->toHaveCount(2);
});

it('exports who the user mutes, blocks and dismissed, but never who mutes, blocks or dismissed them', function (): void {
    $user = User::factory()->create();
    $muted = User::factory()->create(['username' => 'muted-developer']);
    $blocked = User::factory()->create(['username' => 'blocked-developer']);
    $dismissed = User::factory()->create(['username' => 'dismissed-developer']);
    $muter = User::factory()->create(['username' => 'secret-muter']);
    $blocker = User::factory()->create(['username' => 'secret-blocker']);
    $dismisser = User::factory()->create(['username' => 'secret-dismisser']);

    $user->muting()->attach($muted);
    $user->blocking()->attach($blocked);
    $user->dismissedSuggestions()->attach($dismissed);
    $muter->muting()->attach($user);
    $blocker->blocking()->attach($user);
    $dismisser->dismissedSuggestions()->attach($user);

    $response = $this->actingAs($user)->get(route('export'));
    $data = json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR);

    expect($data['muting'])->toBe([['username' => 'muted-developer']])
        ->and($data['blocking'])->toBe([['username' => 'blocked-developer']])
        ->and($data['dismissed_suggestions'])->toBe([['username' => 'dismissed-developer']])
        ->and($response->streamedContent())->not->toContain('secret-muter', 'secret-blocker', 'secret-dismisser', $muted->id, $blocked->id, $dismissed->id);
});

it('also limits exports to five a minute per user independently of the hourly budget', function (): void {
    RateLimiter::for('exports', fn (): Limit => Limit::none());

    $user = User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        $this->actingAs($user)->get(route('export'))->assertOk();
    }

    $this->actingAs($user)->get(route('export'))->assertTooManyRequests();

    $this->actingAs(User::factory()->create())->get(route('export'))->assertOk();

    $this->travel(1)->minute();

    $this->actingAs($user)->get(route('export'))->assertOk();
});

it('exports the messages the user sent, and not the ones they received', function (): void {
    $user = User::factory()->create();
    $partner = User::factory()->create(['username' => 'nunomaduro']);
    $partner->following()->attach($user);
    sendChatMessage($user, $partner, 'merged it this morning');
    sendChatMessage($partner, $user, 'ship it');

    $data = json_decode($this->actingAs($user)->get(route('export'))->streamedContent(), true, flags: JSON_THROW_ON_ERROR);

    expect($data['messages'])->toBe([
        ['to' => 'nunomaduro', 'body' => 'merged it this morning', 'voice' => null, 'created_at' => now()->toIso8601String()],
    ]);
});

it('exports the voice notes the user sent as links', function (): void {
    $user = User::factory()->create();
    $voice = MessageVoice::factory()->for(Message::factory()->for($user)->state(['body' => '']))->create();

    $data = json_decode($this->actingAs($user)->get(route('export'))->streamedContent(), true, flags: JSON_THROW_ON_ERROR);

    expect($data['messages'][0]['voice'])->toBe(route('message-voice.show', $voice));
});

it('exports your remote pins without the pins of other members', function (): void {
    config(['remotes.requests' => true, 'remotes.everyone' => true]);
    $this->freezeSecond();
    $user = User::factory()->withRemotes()->create();
    $partner = User::factory()->withRemotes()->create(['username' => 'alice']);
    sendChatMessage($partner, $user);
    $org = createOrg($user, followersOf($user, 1), 'Release crew');
    $this->actingAs($user)->putJson(route('conversation-pin.update', $partner), ['pinned' => true])->assertOk();
    $this->actingAs($user)->putJson(route('org-pin.update', $org), ['pinned' => true])->assertOk();
    $this->actingAs($partner)->putJson(route('conversation-pin.update', $user), ['pinned' => true])->assertOk();

    $data = json_decode($this->actingAs($user)->get(route('export'))->streamedContent(), true, flags: JSON_THROW_ON_ERROR);

    expect($data['remote_pins'])->toBe([
        ['to' => 'alice', 'pinned_at' => now()->toIso8601String()],
        ['to' => 'org: Release crew', 'pinned_at' => now()->toIso8601String()],
    ]);
});
