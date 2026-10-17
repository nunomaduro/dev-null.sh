<?php

declare(strict_types=1);

namespace App\Contracts\Moderation;

use App\Models\Commit;

interface AiModerationProvider
{
    /**
     * @return list<array{code: string, weight: int}>
     */
    public function signals(Commit $commit): array;
}
