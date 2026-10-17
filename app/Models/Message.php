<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Visible;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property-read int $id
 * @property-read int $conversation_id
 * @property-read string $user_id
 * @property-read string $client_id
 * @property-read string $body
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 */
#[Visible(['id', 'user_id', 'client_id', 'body', 'created_at'])]
final class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    public const int MAX_LENGTH = 2000;

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'integer',
            'conversation_id' => 'integer',
            'user_id' => 'string',
            'client_id' => 'string',
            'body' => 'string',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
