<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\CommitImpressionFactory;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property-read int $id
 * @property-read string $user_id
 * @property-read string $commit_id
 * @property-read CarbonInterface|null $last_counted_at
 */
#[WithoutTimestamps]
final class CommitImpression extends Model
{
    /** @use HasFactory<CommitImpressionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'integer',
            'user_id' => 'string',
            'commit_id' => 'string',
            'last_counted_at' => 'datetime',
        ];
    }
}
