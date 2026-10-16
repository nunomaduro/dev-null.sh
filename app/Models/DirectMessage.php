<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\DirectMessageFactory;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property-read int $id
 * @property-read string $conversation_id
 * @property-read string $sender_id
 * @property-read string $client_id
 * @property-read string $body
 * @property-read CarbonInterface $created_at
 */
#[WithoutTimestamps]
final class DirectMessage extends Model
{
    /** @use HasFactory<DirectMessageFactory> */
    use HasFactory;

    /** @return array<string, string> */
    public function casts(): array
    {
        return ['body' => 'encrypted', 'created_at' => 'datetime'];
    }
}
