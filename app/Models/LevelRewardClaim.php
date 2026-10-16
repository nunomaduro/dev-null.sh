<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LevelRewardClaimFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property-read string $id
 * @property-read string $user_id
 * @property-read int $level
 * @property-read int $bytes
 */
final class LevelRewardClaim extends Model
{
    /** @use HasFactory<LevelRewardClaimFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'user_id' => 'string',
            'level' => 'integer',
            'bytes' => 'integer',
        ];
    }
}
