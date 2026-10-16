<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\CommitImpressionBatchFactory;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property-read int $id
 * @property-read string $user_id
 * @property-read string $batch_id
 * @property-read CarbonInterface $created_at
 */
#[WithoutTimestamps]
final class CommitImpressionBatch extends Model
{
    /** @use HasFactory<CommitImpressionBatchFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'integer',
            'user_id' => 'string',
            'batch_id' => 'string',
            'created_at' => 'datetime',
        ];
    }
}
