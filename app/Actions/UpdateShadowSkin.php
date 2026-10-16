<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Shadow;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

final readonly class UpdateShadowSkin
{
    /**
     * @var array<string, int>
     */
    public const array SKIN_PRICES = [
        'classic-green' => 0,
        'amber-crt' => 50,
        'matrix' => 100,
        'cyber-cyan' => 150,
        'kernel-red' => 250,
    ];

    /**
     * Unlocks a Shadow skin using the user's Byte token balance.
     */
    public function handle(User $user, string $skin): Shadow
    {
        /** @var Shadow $shadow */
        $shadow = Shadow::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'name' => 'daemon-0',
                'glyph' => 'λ',
                'skin' => 'classic-green',
                'unlocked_skins' => ['classic-green'],
            ]
        );

        if (! array_key_exists($skin, self::SKIN_PRICES)) {
            return $shadow;
        }

        $cost = self::SKIN_PRICES[$skin];

        return DB::transaction(function () use ($user, $shadow, $skin, $cost): Shadow {
            if (in_array($skin, $shadow->unlocked_skins, true)) {
                return $shadow;
            }

            if ($cost > 0) {
                /** @var Wallet $wallet */
                $wallet = Wallet::query()->firstOrCreate(
                    ['user_id' => $user->id],
                    ['balance' => 0, 'lifetime_earned' => 0, 'lifetime_tipped' => 0]
                );

                // Deduct atomically so concurrent purchases cannot overdraw the wallet.
                if (Wallet::query()->whereKey($wallet->id)->where('balance', '>=', $cost)->decrement('balance', $cost) === 0) {
                    return $shadow;
                }

                $wallet->increment('lifetime_tipped', $cost);
            }

            $unlocked = $shadow->unlocked_skins;
            $unlocked[] = $skin;
            $shadow->unlocked_skins = array_values(array_unique($unlocked));
            $shadow->save();

            return $shadow;
        });
    }
}
