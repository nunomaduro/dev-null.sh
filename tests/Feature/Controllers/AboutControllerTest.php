<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

it('renders the about page for guests', function (): void {
    fakeGitHubContributors([gitHubContributor('xHeaven', 187)]);

    $this->get(route('about.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('about/show'));
});

it('renders the about page for signed in users', function (): void {
    fakeGitHubContributors([gitHubContributor('xHeaven', 187)]);

    $this->actingAs(User::factory()->create())
        ->get(route('about.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('about/show'));
});

it('describes the about page to search engines and link previews', function (): void {
    fakeGitHubContributors([gitHubContributor('xHeaven', 187)]);

    $this->get(route('about.show'))
        ->assertOk()
        ->assertSee('<title>About - /dev/null</title>', false)
        ->assertSee('<link rel="canonical" href="'.route('about.show').'">', false)
        ->assertSee('"@type":"WebPage"', false);
});

it('hands the contributors to the page', function (): void {
    fakeGitHubContributors([gitHubContributor('xHeaven', 187)]);

    $this->get(route('about.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('contributors', 1)
            ->where('contributors.0', ['login' => 'xHeaven', 'commits' => 187, 'username' => null]));
});

it('links each contributor who is on /dev/null to their profile, found by their GitHub account', function (): void {
    fakeGitHubContributors([gitHubContributor('xHeaven', 217, id: 14284867), gitHubContributor('nunomaduro', 189)]);
    User::factory()->create(['github_id' => '14284867', 'username' => 'renamed-on-devnull']);

    $this->get(route('about.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('contributors.0', ['login' => 'xHeaven', 'commits' => 217, 'username' => 'renamed-on-devnull'])
            ->where('contributors.1', ['login' => 'nunomaduro', 'commits' => 189, 'username' => null]));
});

it('leaves the contributors out while GitHub is unreachable', function (): void {
    Http::fake(fn () => throw new ConnectionException('timeout'));

    $this->get(route('about.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('contributors', null));
});
