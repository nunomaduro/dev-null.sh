<?php

declare(strict_types=1);

use App\Models\Commit;
use App\Models\CommitImage;

it('lists approved commits only', function (): void {
    $commit = Commit::factory()->create();
    $pending = Commit::factory()->pending()->create();
    $rejected = Commit::factory()->rejected()->create();
    $deleted = Commit::factory()->create();
    $deleted->delete();

    $response = $this->get(route('commit-sitemap.show', 1));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->assertSee('<loc>'.route('commit.show', [$commit->user, $commit]).'</loc>', false)
        ->assertSee('<lastmod>'.$commit->updated_at->toAtomString().'</lastmod>', false)
        ->assertDontSee($pending->id)
        ->assertDontSee($rejected->id)
        ->assertDontSee($deleted->id);
});

it('lists the images of approved commits', function (): void {
    $image = CommitImage::factory()->create();

    $this->get(route('commit-sitemap.show', 1))
        ->assertOk()
        ->assertSee('xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"', false)
        ->assertSee('<image:loc>'.$image->url().'</image:loc>', false);
});

it('lists every commit once, with its images in order', function (): void {
    $commit = Commit::factory()->create();
    $second = CommitImage::factory()->for($commit)->create(['position' => 1]);
    $first = CommitImage::factory()->for($commit)->create(['position' => 0]);
    $other = Commit::factory()->create();

    $response = $this->get(route('commit-sitemap.show', 1));

    $response->assertOk()
        ->assertSeeInOrder([
            '<loc>'.route('commit.show', [$commit->user, $commit]).'</loc>',
            '<image:loc>'.$first->url().'</image:loc>',
            '<image:loc>'.$second->url().'</image:loc>',
        ], false);

    expect(mb_substr_count($response->getContent(), '<url>'))->toBe(2)
        ->and(mb_substr_count($response->getContent(), route('commit.show', [$other->user, $other])))->toBe(1);
});

it('does not route pages that are not positive numbers', function (string $page): void {
    $this->get("/sitemaps/commits-{$page}.xml")->assertNotFound();
})->with(['0', 'abc', '-1']);
