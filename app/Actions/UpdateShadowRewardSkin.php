<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Shadow;
use App\Models\User;

final readonly class UpdateShadowRewardSkin
{
    /**
     * Called within the level reward transaction so the wallet and cosmetic unlock roll back together.
     */
    public function handle(User $user, ?string $skin): void
    {
        if ($skin === null) {
            return;
        }

        /** @var Shadow $shadow */
        $shadow = Shadow::query()->firstOrCreate(['user_id' => $user->id], [
            'name' => 'daemon-0',
            'glyph' => 'λ',
            'skin' => 'classic-green',
            'unlocked_skins' => ['classic-green'],
        ]);

        $shadow->unlocked_skins = array_values(array_unique([
            ...$shadow->unlocked_skins,
            $skin,
        ]));
        $shadow->save();
    }
}
