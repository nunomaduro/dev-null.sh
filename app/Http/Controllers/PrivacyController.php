<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PageMetadata;
use Inertia\Inertia;
use Inertia\Response;

final readonly class PrivacyController
{
    public function show(PageMetadata $metadata): Response
    {
        return Inertia::render('privacy/show')->withViewData(['preview' => $metadata->privacy()]);
    }
}
