<?php

declare(strict_types=1);

use App\Actions\CreateCommitImages;
use App\Services\SignedCommits;

it('serves the script', function (): void {
    $response = $this->get(route('cli-script.show'));

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8');

    expect($response->getContent())
        ->toStartWith("#!/usr/bin/env bash\n")
        ->toContain('curl -fsSL '.url('/').'/commit | bash')
        ->toContain('DEVNULL_URL="${DEVNULL_URL:-'.url('/').'}"')
        ->not->toContain('__DEVNULL_URL__');
});

it('signs with the namespace the server verifies', function (): void {
    expect($this->get(route('cli-script.show'))->getContent())
        ->toContain('NAMESPACE="'.SignedCommits::Namespace.'"')
        ->toContain('NAMESPACE="'.SignedCommits::ImagesNamespace.'"');
});

it('carries the image limits the server enforces', function (): void {
    $maxBytes = (int) CreateCommitImages::MAX_UPLOAD_SIZE * 1_000 * 1_024;

    expect($this->get(route('cli-script.show'))->getContent())
        ->toContain('-le '.CreateCommitImages::MAX_IMAGES.' ]')
        ->toContain('up to '.CreateCommitImages::MAX_IMAGES.' images.')
        ->toContain('-le '.$maxBytes.' ]');
});
