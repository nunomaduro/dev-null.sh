<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\ShadowFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read string $id
 * @property-read string $user_id
 * @property string $name
 * @property string $glyph
 * @property string $skin
 * @property string|null $title
 * @property list<string> $unlocked_skins
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 * @property-read User $user
 */
final class Shadow extends Model
{
    /** @use HasFactory<ShadowFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'name' => 'daemon-0',
        'glyph' => 'λ',
        'skin' => 'classic-green',
        'unlocked_skins' => '["classic-green"]',
    ];

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'user_id' => 'string',
            'name' => 'string',
            'glyph' => 'string',
            'skin' => 'string',
            'title' => 'string',
            'unlocked_skins' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
