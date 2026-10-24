<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\TopicFactory;
use Illuminate\Database\Eloquent\Attributes\Visible;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property-read string $id
 * @property-read string $value
 * @property-read CarbonInterface $last_applied_at
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 * @property-read Collection<int, Commit> $commits
 */
#[Visible(['value'])]
final class Topic extends Model
{
    /** @use HasFactory<TopicFactory> */
    use HasFactory;

    use HasUuids;
    use MassPrunable;

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'value' => 'string',
            'last_applied_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<Commit, $this>
     */
    public function commits(): BelongsToMany
    {
        return $this->belongsToMany(Commit::class);
    }

    /**
     * Lock the limited delete's selected rows so an assignment refreshing a topic cannot race its expiry.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return self::query()->where('last_applied_at', '<', now()->subMonthNoOverflow())->orderBy('id')->lockForUpdate();
    }
}
