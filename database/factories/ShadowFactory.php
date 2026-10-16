<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Shadow;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shadow>
 */
final class ShadowFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'daemon-0',
            'glyph' => 'λ',
            'skin' => 'classic-green',
            'title' => 'Background Worker',
            'unlocked_skins' => ['classic-green'],
        ];
    }
}
