<?php

declare(strict_types=1);

use App\Models\Commit;
use App\Models\CommitImage;

it('keeps image URLs ordered by their position', function (): void {
    $commit = Commit::factory()->create();
    $last = CommitImage::factory()->for($commit)->create(['position' => 20]);
    $first = CommitImage::factory()->for($commit)->create(['position' => 10]);

    $this->get(route('commit-sitemap.show', 1))
        ->assertOk()
        ->assertSeeInOrder([$first->url(), $last->url()], false);
});

it('preserves the existing sitemap ETag response contract', function (): void {
    Commit::factory()->create();

    $response = $this->get(route('commit-sitemap.show', 1));

    $response->assertOk();

    $etag = $response->headers->get('ETag');

    expect($etag)->not->toBeNull();

    $this->withHeaders(['If-None-Match' => $etag])
        ->get(route('commit-sitemap.show', 1))
        ->assertNotModified();
});
