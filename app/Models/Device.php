<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Attributes\Visible;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An SSH key the user approved for the devnull app on the web, so the app may sign API requests with it as them.
 * The approval is the proof: the key doesn't have to be on their GitHub account. It stays until the user revokes it.
 *
 * @property-read string $id
 * @property-read string $user_id
 * @property-read string $fingerprint
 * @property-read string $name
 * @property-read CarbonInterface $approved_at
 * @property-read CarbonInterface|null $last_used_at
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 * @property-read User $user
 */
#[Visible([
    'id',
    'name',
    'fingerprint',
    'approved_at',
    'last_used_at',
])]
final class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
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
            'fingerprint' => 'string',
            'name' => 'string',
            'approved_at' => 'datetime',
            'last_used_at' => 'datetime',
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
