<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PageMetadata;
use Inertia\Inertia;
use Inertia\Response;

final readonly class TermsController
{
    public function show(PageMetadata $metadata): Response
    {
        return Inertia::render('terms/show')->withViewData(['preview' => $metadata->terms()]);
    }
}
